<?php

namespace Elig45\BagistoHelloAsso\Services;

use Elig45\BagistoHelloAsso\Models\CheckoutAttempt;
use Elig45\BagistoHelloAsso\Models\Connection;
use Illuminate\Support\Str;
use Webkul\Sales\Transformers\OrderResource;

class HelloAssoCheckoutService
{
    public function __construct(
        protected HelloAssoApiClient $api,
        protected InstallmentPlanBuilder $plans,
    ) {}

    public function start(Connection $connection, object $cart, int $installmentCount = 1): CheckoutAttempt
    {
        if (! $connection->organization_slug || ! $connection->isProductionAllowed()) {
            throw new \RuntimeException('HelloAsso is not configured for this channel.');
        }

        if (strtoupper((string) $cart->cart_currency_code) !== 'EUR') {
            throw new \RuntimeException('HelloAsso Checkout supports EUR carts only.');
        }

        $amount = (int) round((float) $cart->grand_total * 100);

        if ($amount < 100) {
            throw new \RuntimeException('HelloAsso Checkout requires a positive amount of at least one euro.');
        }

        $existing = CheckoutAttempt::where('cart_id', $cart->id)
            ->where('channel_code', $connection->channel_code)
            ->whereIn('status', ['redirected', 'pending', 'partially_paid'])
            ->where('created_at', '>=', now()->subMinutes(15))
            ->latest('id')
            ->first();

        if ($existing) {
            if ((int) $existing->installment_count === $installmentCount && (int) $existing->total_amount === $amount) {
                return $existing;
            }

            throw new \RuntimeException('A HelloAsso Checkout is already open for this cart.');
        }

        $reference = (string) Str::uuid();
        $plan = ['initialAmount' => $amount, 'terms' => []];
        $payload = [
            'totalAmount' => $amount,
            'initialAmount' => $amount,
            'itemName' => mb_substr('Commande boutique '.$cart->id, 0, 250),
            'backUrl' => route('helloasso.payment.cancel', ['channel' => $connection->channel_code]),
            'errorUrl' => route('helloasso.payment.error', ['channel' => $connection->channel_code]),
            'returnUrl' => route('helloasso.payment.return', ['channel' => $connection->channel_code]),
            'containsDonation' => false,
            'payer' => $this->payer($cart),
            'metadata' => [
                'bagisto_ref' => $reference,
                'cart_id' => (string) $cart->id,
                'channel_code' => $connection->channel_code,
                'organization_slug' => $connection->organization_slug,
                'currency' => 'EUR',
            ],
        ];

        if ($installmentCount > 1) {
            if (! in_array($installmentCount, $connection->installment_counts ?? [], true)) {
                throw new \InvalidArgumentException('The selected installment plan is not configured.');
            }

            $schedule = $this->plans->schedule($amount, $installmentCount, (int) $connection->initial_percent);
            $plan = $schedule;
            $payload['initialAmount'] = $schedule['initialAmount'];
            $payload['terms'] = $schedule['terms'];
        }

        if ($connection->enable_sepa) {
            $payload['paymentOptions'] = ['enableSepa' => true];
        }

        $response = $this->api->post(
            $connection,
            '/organizations/'.rawurlencode($connection->organization_slug).'/checkout-intents',
            $payload,
        );

        $redirectUrl = $response['redirectUrl'] ?? null;

        if (
            ! is_string($redirectUrl)
            || ! $this->isHelloAssoUrl($redirectUrl, $connection->isSandbox())
            || (int) ($response['id'] ?? 0) <= 0
        ) {
            throw new \RuntimeException('HelloAsso did not return a valid checkout URL.');
        }

        return CheckoutAttempt::create([
            'cart_id' => $cart->id,
            'channel_code' => $connection->channel_code,
            'organization_slug' => $connection->organization_slug,
            'checkout_intent_id' => (int) ($response['id'] ?? 0),
            'reference' => $reference,
            'installment_plan' => $plan,
            'total_amount' => $amount,
            'currency' => 'EUR',
            'installment_count' => $installmentCount,
            'status' => 'redirected',
            'redirect_url' => $redirectUrl,
            'cart_snapshot' => (new OrderResource($cart))->jsonSerialize(),
        ]);
    }

    protected function payer(object $cart): array
    {
        $address = $cart->billing_address;

        return array_filter([
            'firstName' => $address?->first_name,
            'lastName' => $address?->last_name,
            'email' => $cart->customer_email,
            'address' => $address?->address1 ? implode(' ', (array) $address->address1) : null,
            'city' => $address?->city,
            'zipCode' => $address?->postcode,
            'country' => $this->countryCode($address?->country),
        ], fn ($value) => $value !== null && $value !== '');
    }

    protected function countryCode(?string $country): ?string
    {
        if (! $country) {
            return null;
        }

        $country = strtoupper($country);

        if (strlen($country) === 3) {
            return $country;
        }

        return [
            'AT' => 'AUT', 'BE' => 'BEL', 'CA' => 'CAN', 'CH' => 'CHE',
            'DE' => 'DEU', 'DK' => 'DNK', 'ES' => 'ESP', 'FI' => 'FIN',
            'FR' => 'FRA', 'GB' => 'GBR', 'GR' => 'GRC', 'IE' => 'IRL',
            'IT' => 'ITA', 'LU' => 'LUX', 'NL' => 'NLD', 'NO' => 'NOR',
            'PL' => 'POL', 'PT' => 'PRT', 'SE' => 'SWE', 'US' => 'USA',
        ][$country] ?? null;
    }

    protected function isHelloAssoUrl(string $url, bool $sandbox): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! is_string($host)) {
            return false;
        }

        $allowed = $sandbox ? ['helloasso-sandbox.com'] : ['helloasso.com'];

        foreach ($allowed as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }
}
