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
        return $this->environment !== 'production';
    }

    public function isProductionAllowed(): bool
    {
        return $this->isSandbox() || (bool) config('helloasso.allow_production', false);
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
        return $this->isSandbox()
            ? 'https://api.helloasso-sandbox.com/v5'
            : 'https://api.helloasso.com/v5';
    }

    public function oauthBaseUrl(): string
    {
        return $this->isSandbox()
            ? 'https://api.helloasso-sandbox.com'
            : 'https://api.helloasso.com';
    }
}
