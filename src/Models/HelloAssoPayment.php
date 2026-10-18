<?php

namespace Elig45\BagistoHelloAsso\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HelloAssoPayment extends Model
{
    protected $table = 'helloasso_payments';

    protected $guarded = ['id'];

    protected $casts = [
        'remote_data' => 'array',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(CheckoutAttempt::class, 'checkout_attempt_id');
    }
}
