<?php

namespace Elig45\BagistoHelloAsso\Http\Controllers\Shop;

use Elig45\BagistoHelloAsso\Models\CheckoutAttempt;
use Elig45\BagistoHelloAsso\Models\Connection;
use Elig45\BagistoHelloAsso\Models\WebhookReceipt;
use Elig45\BagistoHelloAsso\Services\HelloAssoApiClient;
use Elig45\BagistoHelloAsso\Services\HelloAssoCheckoutService;
use Elig45\BagistoHelloAsso\Services\OrderSettlementService;
use Elig45\BagistoHelloAsso\Services\OrganizationEligibilityService;
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

        $connection = Connection::globalConnection();

        if (! $connection || ! $connection->organization_slug) {
            return redirect()->route('shop.checkout.cart.index')
                ->with('error', trans('helloasso::app.checkout.unavailable'));
        }

        abort_unless(
            (bool) core()->getConfigData('sales.payment_methods.helloasso.active')
                && data_get($cart, 'payment.method') === 'helloasso'
                && $connection->isProductionAllowed()
                && ! ($connection->environment === 'production' && $connection->cash_in_eligible === false),
            403,
        );

        $count = (int) session()->pull('helloasso.installment_count.'.$cart->id, 1);

        if ($count !== 1 && ! in_array($count, $connection->installment_counts ?? [], true)) {
            $count = 1;
        }

        try {
            $attempt = $this->checkout->start($connection, $cart, $channelCode, $count);
            session()->put('helloasso.attempt.'.$channelCode, $attempt->id);

            return redirect()->away($attempt->redirect_url);
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('shop.checkout.cart.index')
                ->with('error', trans('helloasso::app.checkout.start-failed'));
        }
    }

    public function selectPlan(Request $request, string $channelCode): JsonResponse
    {
        $connection = Connection::globalConnection();
        $cart = Cart::getCart();

        abort_unless(
            $connection
            && $cart
            && core()->getCurrentChannelCode() === $channelCode,
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

    public function returned(Request $request, string $channelCode): RedirectResponse
    {
        $connection = Connection::globalConnection();
        $intentId = $request->query('checkoutIntentId');

        if (
            ! $connection
            || core()->getCurrentChannelCode() !== $channelCode
            || ! ctype_digit((string) $intentId)
        ) {
            return redirect()->route('shop.checkout.cart.index')
                ->with('error', trans('helloasso::app.checkout.invalid-return'));
        }

        $attempt = CheckoutAttempt::where('channel_code', $channelCode)
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

        if (in_array($status, ['metadata_mismatch', 'amount_mismatch', 'invalid_attempt', 'snapshot_missing'], true)) {
            return redirect()->route('shop.checkout.cart.index')
                ->with('error', trans('helloasso::app.checkout.verification-failed'));
        }

        if ($status === 'payment_refused') {
            return redirect()->route('shop.checkout.cart.index')
                ->with('error', trans('helloasso::app.checkout.remote-error'));
        }

        if (
            $attempt->fresh()->order_id
            && in_array($status, ['order_ready', 'order_pending_payment'], true)
        ) {
            session()->flash('order_id', $attempt->fresh()->order_id);
        }

        if (
            $attempt->fresh()->order_id
            && in_array($status, ['order_ready', 'order_pending_payment'], true)
        ) {
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

    public function cancel(string $channelCode): RedirectResponse
    {
        $attemptId = session()->pull('helloasso.attempt.'.$channelCode);
        $attempt = $attemptId ? CheckoutAttempt::where('id', $attemptId)->where('channel_code', $channelCode)->first() : null;

        if ($attempt && ! $attempt->order_id) {
            $attempt->update(['status' => 'cancel_returned']);
        }

        return redirect()->route('shop.checkout.cart.index')
            ->with('info', trans('helloasso::app.checkout.payment-cancelled'));
    }

    public function error(string $channelCode): RedirectResponse
    {
        $attemptId = session()->pull('helloasso.attempt.'.$channelCode);
        $attempt = $attemptId ? CheckoutAttempt::where('id', $attemptId)->where('channel_code', $channelCode)->first() : null;

        if ($attempt && ! $attempt->order_id) {
            $attempt->update(['status' => 'error_returned']);
        }

        return redirect()->route('shop.checkout.cart.index')
            ->with('error', trans('helloasso::app.checkout.remote-error'));
    }

    public function webhook(
        Request $request,
        WebhookSignatureVerifier $signatures,
        OrganizationEligibilityService $eligibility,
        ?string $channelCode = null,
    ): JsonResponse {
        $connection = Connection::globalConnection();

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

        $eventType = (string) ($payload['eventType'] ?? '');

        if (! in_array($eventType, ['Order', 'Payment', 'Organization', 'Organization.IsCashinCompliant'], true)) {
            return response()->json(['status' => 'ignored']);
        }

        $payloadHash = hash('sha256', $body);
        $receipt = WebhookReceipt::firstOrCreate([
            'payload_hash' => $payloadHash,
        ], [
            'channel_code' => $channelCode ?? Connection::GLOBAL_KEY,
            'event_type' => $eventType,
            'status' => 'received',
        ]);

        if (! $receipt->wasRecentlyCreated && $receipt->status === 'processed') {
            return response()->json(['status' => 'duplicate']);
        }

        if (str_starts_with($eventType, 'Organization')) {
            try {
                $processed = Cache::lock('helloasso.webhook.'.$receipt->payload_hash, 600)->block(10, function () use ($payload, $connection, $receipt, $eligibility) {
                    $receipt->refresh();

                    if ($receipt->status === 'processed') {
                        return 'duplicate';
                    }

                    $eventData = (array) ($payload['data'] ?? []);

                    if (! $eligibility->matchesNotification($connection, $eventData)) {
                        $receipt->update(['status' => 'ignored']);

                        return 'ignored';
                    }

                    if ($connection->webhook_signature_key) {
                        $eligibility->applyNotification($connection, $eventData);
                    } else {
                        $organization = $this->api->get(
                            $connection,
                            '/organizations/'.rawurlencode($connection->organization_slug),
                        );
                        $eligibility->recordApiResult($connection, $organization);
                    }

                    $receipt->update(['status' => 'processed']);

                    return 'processed';
                });

                return response()->json(['status' => $processed]);
            } catch (\Throwable $exception) {
                report($exception);
                $receipt->update(['status' => 'failed']);

                return response()->json(['status' => 'retry'], 500);
            }
        }

        $eventData = (array) ($payload['data'] ?? []);
        $intentId = $this->findIntentId($eventData);
        $paymentId = $eventType === 'Payment' ? $this->findPaymentId($eventData) : null;

        if (! $paymentId && $eventType === 'Payment' && isset($eventData['id']) && ctype_digit((string) $eventData['id'])) {
            $paymentId = (int) $eventData['id'];
        }

        if (! $intentId && $paymentId) {
            try {
                $payment = $this->api->get($connection, '/payments/'.$paymentId);

                if ((int) ($payment['id'] ?? 0) === $paymentId) {
                    $intentId = (int) ($payment['checkoutIntentId'] ?? 0) ?: null;

                    if (! $intentId) {
                        $orderId = $this->findOrderId($eventData) ?? $this->findOrderId($payment);

                        if ($orderId) {
                            $order = $this->api->get($connection, '/orders/'.$orderId);

                            if (
                                (int) ($order['id'] ?? 0) === $orderId
                                && (! isset($order['organizationSlug'])
                                    || $order['organizationSlug'] === $connection->organization_slug)
                            ) {
                                $intentId = (int) ($order['checkoutIntentId'] ?? 0) ?: null;
                            }
                        }
                    }
                }
            } catch (\Throwable $exception) {
                report($exception);
                $receipt->update(['status' => 'failed']);

                return response()->json(['status' => 'retry'], 500);
            }
        }

        if (! $intentId) {
            $receipt->update(['status' => 'ignored']);

            return response()->json(['status' => 'checkout_not_found']);
        }

        $attempt = CheckoutAttempt::where('organization_slug', $connection->organization_slug)
            ->where('checkout_intent_id', $intentId)
            ->when($channelCode, fn ($query) => $query->where('channel_code', $channelCode))
            ->first();

        if (! $attempt) {
            $receipt->update(['status' => 'ignored']);

            return response()->json(['status' => 'checkout_not_found']);
        }

        try {
            $trustedPaymentNotification = $eventType === 'Payment' && filled($connection->webhook_signature_key);
            $processed = Cache::lock('helloasso.webhook.'.$receipt->payload_hash, 600)->block(10, function () use ($attempt, $connection, $receipt, $paymentId, $trustedPaymentNotification) {
                $receipt->refresh();

                if ($receipt->status === 'processed') {
                    return false;
                }

                $this->settlement->reconcile($attempt, $connection, $paymentId, $trustedPaymentNotification);

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

    protected function findPaymentId(array $data): ?int
    {
        foreach ($data as $key => $value) {
            if (
                in_array($key, ['paymentId', 'payment_id'], true)
                && ctype_digit((string) $value)
                && (int) $value > 0
            ) {
                return (int) $value;
            }

            if (is_array($value) && ($found = $this->findPaymentId($value))) {
                return $found;
            }
        }

        return null;
    }

    protected function findOrderId(array $data): ?int
    {
        foreach ($data as $key => $value) {
            if (
                in_array($key, ['orderId', 'order_id'], true)
                && ctype_digit((string) $value)
                && (int) $value > 0
            ) {
                return (int) $value;
            }

            if (
                $key === 'order'
                && is_array($value)
                && ctype_digit((string) ($value['id'] ?? ''))
                && (int) $value['id'] > 0
            ) {
                return (int) $value['id'];
            }

            if (is_array($value) && ($found = $this->findOrderId($value))) {
                return $found;
            }
        }

        return null;
    }
}
