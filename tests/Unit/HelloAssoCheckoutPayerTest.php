<?php

use Elig45\BagistoHelloAsso\Services\HelloAssoCheckoutService;

function checkoutPayerBuilder(): HelloAssoCheckoutService
{
    return new class extends HelloAssoCheckoutService
    {
        public function __construct() {}

        public function payerFor(object $cart): array
        {
            return $this->payer($cart);
        }

        public function retryWithoutPayerNames(array $payload): array
        {
            return $this->removeInvalidPayerNames($payload);
        }
    };
}

it('prefills payer details from the Bagisto cart and billing address', function () {
    $cart = (object) [
        'customer_first_name' => 'Account',
        'customer_last_name' => 'Holder',
        'customer_email' => 'payer@example.org',
        'billing_address' => (object) [
            'first_name' => 'Jean',
            'last_name' => 'Dupont',
            'email' => 'billing@example.org',
            'address' => ['12 rue de Paris'],
            'city' => 'Paris',
            'postcode' => '75001',
            'country' => 'FR',
        ],
    ];

    expect(checkoutPayerBuilder()->payerFor($cart))->toBe([
        'firstName' => 'Jean',
        'lastName' => 'Dupont',
        'email' => 'payer@example.org',
        'address' => '12 rue de Paris',
        'city' => 'Paris',
        'zipCode' => '75001',
        'country' => 'FRA',
    ]);
});

it('keeps valid payer data when a name cannot be prefilled by HelloAsso', function () {
    $cart = (object) [
        'customer_first_name' => 'Jean',
        'customer_last_name' => "O'Connor",
        'customer_email' => 'payer@example.org',
        'billing_address' => (object) [
            'first_name' => 'Jean',
            'last_name' => "O'Connor",
        ],
    ];

    expect(checkoutPayerBuilder()->payerFor($cart))->toBe([
        'firstName' => 'Jean',
        'email' => 'payer@example.org',
    ]);
});

it('uses the Bagisto customer fields when the billing address has no names', function () {
    $cart = (object) [
        'customer_first_name' => 'Alice',
        'customer_last_name' => 'Martin',
        'customer_email' => 'alice@example.org',
        'billing_address' => (object) [],
    ];

    expect(checkoutPayerBuilder()->payerFor($cart))->toBe([
        'firstName' => 'Alice',
        'lastName' => 'Martin',
        'email' => 'alice@example.org',
    ]);
});

it('retains the email and other payer data when retrying after a name validation error', function () {
    $payload = [
        'totalAmount' => 2500,
        'payer' => [
            'firstName' => 'Jean',
            'lastName' => "O'Connor",
            'email' => 'payer@example.org',
            'city' => 'Paris',
        ],
    ];

    expect(checkoutPayerBuilder()->retryWithoutPayerNames($payload))->toBe([
        'totalAmount' => 2500,
        'payer' => [
            'email' => 'payer@example.org',
            'city' => 'Paris',
        ],
    ]);
});
