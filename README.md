# Bagisto HelloAsso

A reusable HelloAsso Checkout integration for Bagisto 2.4 and PHP 8.3/8.4.

The package is under active development. Production credentials must never be committed.

## Installation

```sh
composer require elig45/bagisto-helloasso
php artisan migrate
```

Configure the payment method for each Bagisto channel in the admin panel. Sandbox is the default; enable production only after verifying the association and completing a sandbox checkout.

## Features

- HelloAsso Checkout with card, optional SEPA and merchant-defined installments.
- Association API keys and optional partner OAuth authorization.
- Server-side payment reconciliation, idempotent notifications and Bagisto order/invoice updates.
- Configurable order readiness policy and refund synchronization.

See the administration and operations sections below as implementation lands.
