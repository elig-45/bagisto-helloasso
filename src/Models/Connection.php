<?php

namespace Elig45\BagistoHelloAsso\Models;

use Illuminate\Database\Eloquent\Model;

class Connection extends Model
{
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

    public function isSandbox(): bool
    {
        return $this->environment !== 'production';
    }

    public function isProductionAllowed(): bool
    {
        return $this->isSandbox() || (bool) config('helloasso.allow_production', true);
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
