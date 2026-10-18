<?php

namespace Elig45\BagistoHelloAsso\Http\Controllers\Shop;

use Elig45\BagistoHelloAsso\Models\CheckoutAttempt;
use Elig45\BagistoHelloAsso\Models\Connection;
use Elig45\BagistoHelloAsso\Models\WebhookReceipt;
use Elig45\BagistoHelloAsso\Services\HelloAssoApiClient;
use Elig45\BagistoHelloAsso\Services\HelloAssoCheckoutService;
use Elig45\BagistoHelloAsso\Services\OrderSettlementService;
use Elig45\BagistoHelloAsso\Services\WebhookSignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Webkul\Checkout\Facades\Cart;
use Webkul\Checkout\Repositories\CartRepository;
use Webkul\Shop\Http\Controllers\Controller;

class CheckoutController extends Controller
{
    public function __construct(
        protected HelloAssoCheckoutService $checkout,
        protected HelloAssoApiClient $api,
        protected OrderSettlementService $settlement,
        protected CartRepository $carts,
    ) {}

    public function redirect(): RedirectResponse
    {
        $cart = Cart::getCart();
        $channelCode = core()->getCurrentChannelCode();

        if (! $cart || ! $channelCode) {
            return redirect()->route('shop.checkout.cart.index')
                ->with('error', trans('helloasso::app.checkout.cart-unavailable'));
        }

        $connection = Connection::where('channel_code', $channelCode)->first();

        if (! $connection || ! $connection->organization_slug) {
            return redirect()->route('shop.checkout.cart.index')
                ->with('error', trans('helloasso::app.checkout.unavailable'));
        }

        abort_unless(
            $connection->enabled && data_get($cart, 'payment.method') === 'helloasso',
            403,
        );

        $count = (int) session()->pull('helloasso.installment_count.'.$cart->id, 1);

        try {
            $attempt = $this->checkout->start($connection, $cart, $count);
            session()->put('helloasso.attempt.'.$channelCode, $attempt->id);

            return redirect()->away($attempt->redirect_url);
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('shop.checkout.cart.index')
                ->with('error', trans('helloasso::app.checkout.start-failed'));
        }
    }

    public function selectPlan(Request $request, string $channel): JsonResponse
    {
        $connection = Connection::where('channel_code', $channel)->firstOrFail();
        $cart = Cart::getCart();

        abort_unless(
            $cart
            && core()->getCurrentChannelCode() === $connection->channel_code,
            403,
        );

        $data = $request->validate([
            'installment_count' => ['required', 'integer', 'between:1,12'],
        ]);

        $count = (int) $data['installment_count'];

        abort_unless($count === 1 || in_array($count, $connection->installment_counts ?? [], true), 422);

        session()->put('helloasso.installment_count.'.$cart->id, $count);

        return response()->json(['selected' => $count]);
    }

    public function returned(Request $request, string $channel): RedirectResponse
    {
        $connection = Connection::where('channel_code', $channel)->first();
        $intentId = $request->query('checkoutIntentId');

        if (! $connection || ! ctype_digit((string) $intentId)) {
            return redirect()->route('shop.checkout.cart.index')
                ->with('error', trans('helloasso::app.checkout.invalid-return'));
        }

        $attempt = CheckoutAttempt::where('channel_code', $channel)
            ->where('organization_slug', $connection->organization_slug)
            ->where('checkout_intent_id', (int) $intentId)
            ->first();

        if (! $attempt) {
            return redirect()->route('shop.checkout.cart.index')
                ->with('error', trans('helloasso::app.checkout.payment-not-found'));
        }

        try {
            $status = $this->settlement->reconcile($attempt, $connection);
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('shop.checkout.cart.index')
                ->with('error', trans('helloasso::app.checkout.verification-failed'));
        }

        if ($attempt->fresh()->order_id) {
            session()->flash('order_id', $attempt->fresh()->order_id);
        }

        if ($attempt->fresh()->order_id) {
            return redirect()->route('shop.checkout.onepage.success')
                ->with(
                    $status === 'order_ready' ? 'success' : 'info',
                    $status === 'order_ready'
                        ? trans('helloasso::app.checkout.payment-confirmed')
                        : trans('helloasso::app.checkout.order-pending-payment'),
                );
        }

        return redirect()->route('shop.checkout.cart.index')
            ->with('info', trans('helloasso::app.checkout.payment-pending'));
    }

    public function cancel(string $channel): RedirectResponse
    {
        $attemptId = session()->pull('helloasso.attempt.'.$channel);
        $attempt = $attemptId ? CheckoutAttempt::where('id', $attemptId)->where('channel_code', $channel)->first() : null;

        if ($attempt && ! $attempt->order_id) {
            $attempt->update(['status' => 'cancel_returned']);
        }

        return redirect()->route('shop.checkout.cart.index')
            ->with('info', trans('helloasso::app.checkout.payment-cancelled'));
    }

    public function error(string $channel): RedirectResponse
    {
        $attemptId = session()->pull('helloasso.attempt.'.$channel);
        $attempt = $attemptId ? CheckoutAttempt::where('id', $attemptId)->where('channel_code', $channel)->first() : null;

        if ($attempt && ! $attempt->order_id) {
            $attempt->update(['status' => 'error_returned']);
        }

        return redirect()->route('shop.checkout.cart.index')
            ->with('error', trans('helloasso::app.checkout.remote-error'));
    }

    public function webhook(Request $request, string $channel, WebhookSignatureVerifier $signatures): JsonResponse
    {
        $connection = Connection::where('channel_code', $channel)->first();

        if (! $connection) {
            return response()->json(['status' => 'unknown_channel'], 404);
        }

        $body = $request->getContent();
        $signature = $request->header('x-ha-signature');

        if (
            $connection->webhook_signature_key
            && (
                ! $signatures->isValid($body, $signature, $connection->webhook_signature_key)
            )
        ) {
            return response()->json(['status' => 'invalid_signature'], 400);
        }

        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return response()->json(['status' => 'invalid_payload'], 400);
        }

        if (! in_array($payload['eventType'] ?? null, ['Order', 'Payment'], true)) {
            return response()->json(['status' => 'ignored']);
        }

        $payloadHash = hash('sha256', $channel."\0".$body);
        $receipt = WebhookReceipt::firstOrCreate([
            'payload_hash' => $payloadHash,
        ], [
            'channel_code' => $channel,
            'event_type' => (string) $payload['eventType'],
            'status' => 'received',
        ]);

        if (! $receipt->wasRecentlyCreated && $receipt->status === 'processed') {
            return response()->json(['status' => 'duplicate']);
        }

        $intentId = $this->findIntentId($payload['data'] ?? []);

        if (! $intentId) {
            $receipt->update(['status' => 'ignored']);

            return response()->json(['status' => 'checkout_not_found']);
        }

        $attempt = CheckoutAttempt::where('channel_code', $channel)
            ->where('organization_slug', $connection->organization_slug)
            ->where('checkout_intent_id', $intentId)
            ->first();

        if (! $attempt) {
            $receipt->update(['status' => 'ignored']);

            return response()->json(['status' => 'checkout_not_found']);
        }

        try {
            $processed = Cache::lock('helloasso.webhook.'.$receipt->payload_hash, 600)->block(10, function () use ($attempt, $connection, $receipt) {
                $receipt->refresh();

                if ($receipt->status === 'processed') {
                    return false;
                }

                $this->settlement->reconcile($attempt, $connection);

                $receipt->update(['status' => 'processed']);

                return true;
            });

            if (! $processed) {
                return response()->json(['status' => 'duplicate']);
            }

            return response()->json(['status' => 'processed']);
        } catch (\Throwable $exception) {
            report($exception);
            $receipt->update(['status' => 'failed']);

            return response()->json(['status' => 'retry'], 500);
        }
    }

    protected function findIntentId(array $data): ?int
    {
        foreach ($data as $key => $value) {
            if (in_array($key, ['checkoutIntentId', 'checkout_intent_id'], true) && ctype_digit((string) $value)) {
                return (int) $value;
            }

            if (is_array($value) && ($found = $this->findIntentId($value))) {
                return $found;
            }
        }

        return null;
    }
}
