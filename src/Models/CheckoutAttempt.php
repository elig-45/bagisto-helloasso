<?php

namespace Elig45\BagistoHelloAsso\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CheckoutAttempt extends Model
{
    protected $table = 'helloasso_checkout_attempts';

    protected $guarded = ['id'];

    protected $casts = [
        'installment_plan' => 'array',
        'remote_order' => 'encrypted:array',
        'cart_snapshot' => 'encrypted:array',
        'fulfillment_ready' => 'boolean',
        'future_payments_canceled' => 'boolean',
        'last_checked_at' => 'immutable_datetime',
    ];

    public function payments(): HasMany
    {
        return $this->hasMany(HelloAssoPayment::class, 'checkout_attempt_id');
    }
}
