<?php

namespace Elig45\BagistoHelloAsso\Models;

use Illuminate\Database\Eloquent\Model;

class Connection extends Model
{
    public const GLOBAL_KEY = 'global';

    protected $table = 'helloasso_connections';

    protected $guarded = ['id'];

    protected $casts = [
        'enable_sepa' => 'boolean',
        'cash_in_eligible' => 'boolean',
        'cancel_future_terms_on_full_refund' => 'boolean',
        'client_secret' => 'encrypted',
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'webhook_signature_key' => 'encrypted',
        'installment_counts' => 'array',
        'token_expires_at' => 'immutable_datetime',
        'cash_in_checked_at' => 'immutable_datetime',
    ];

    public static function globalConnection(): ?self
    {
        return static::where('channel_code', self::GLOBAL_KEY)->first();
    }

    public function isSandbox(): bool
    {
        return $this->environment === 'sandbox';
    }

    public function isProductionAllowed(): bool
    {
        return match ($this->environment) {
            'sandbox' => true,
            'production' => (bool) config('helloasso.allow_production', false),
            default => false,
        };
    }

    public function canManageAttempt(CheckoutAttempt $attempt): bool
    {
        return $this->isProductionAllowed()
            && $this->environment === $attempt->environment
            && $this->auth_mode === $attempt->auth_mode
            && hash_equals((string) $this->organization_slug, (string) $attempt->organization_slug);
    }

    public function apiBaseUrl(): string
    {
        return match ($this->environment) {
            'sandbox' => 'https://api.helloasso-sandbox.com/v5',
            'production' => 'https://api.helloasso.com/v5',
            default => throw new \UnexpectedValueException('Unsupported HelloAsso environment.'),
        };
    }

    public function oauthBaseUrl(): string
    {
        return match ($this->environment) {
            'sandbox' => 'https://api.helloasso-sandbox.com',
            'production' => 'https://api.helloasso.com',
            default => throw new \UnexpectedValueException('Unsupported HelloAsso environment.'),
        };
    }
}
