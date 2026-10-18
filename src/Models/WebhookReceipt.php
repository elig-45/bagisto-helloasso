<?php

namespace Elig45\BagistoHelloAsso\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookReceipt extends Model
{
    protected $table = 'helloasso_webhook_receipts';

    protected $guarded = ['id'];
}
