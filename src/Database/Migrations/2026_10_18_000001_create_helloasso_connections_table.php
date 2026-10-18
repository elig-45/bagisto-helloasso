<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('helloasso_connections', function (Blueprint $table) {
            $table->id();
            $table->string('channel_code')->unique();
            $table->boolean('enabled')->default(false);
            $table->string('environment')->default('sandbox');
            $table->string('auth_mode')->default('association');
            $table->string('organization_slug')->nullable();
            $table->string('client_id')->nullable();
            $table->text('client_secret')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->text('webhook_signature_key')->nullable();
            $table->boolean('enable_sepa')->default(false);
            $table->json('installment_counts')->nullable();
            $table->unsignedTinyInteger('initial_percent')->default(50);
            $table->string('order_policy')->default('first_authorized');
            $table->boolean('cancel_future_terms_on_full_refund')->default(true);
            $table->timestamps();
        });

        Schema::create('helloasso_checkout_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cart_id');
            $table->string('channel_code');
            $table->string('organization_slug');
            $table->unsignedBigInteger('checkout_intent_id')->nullable();
            $table->string('reference', 36)->unique();
            $table->unsignedBigInteger('remote_order_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('total_amount');
            $table->string('currency', 3)->default('EUR');
            $table->unsignedTinyInteger('installment_count')->default(1);
            $table->json('installment_plan')->nullable();
            $table->string('status')->default('created');
            $table->string('redirect_url', 2048)->nullable();
            $table->longText('remote_order')->nullable();
            $table->longText('cart_snapshot')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_refund_checked_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_slug', 'checkout_intent_id'], 'ha_attempt_intent_unique');
            $table->index(['status', 'last_checked_at']);
            $table->index(['cart_id', 'channel_code']);
        });

        Schema::create('helloasso_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checkout_attempt_id')->constrained('helloasso_checkout_attempts')->cascadeOnDelete();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('payment_id');
            $table->unsignedInteger('installment_number')->nullable();
            $table->unsignedBigInteger('amount');
            $table->string('state');
            $table->string('payment_means')->nullable();
            $table->unsignedBigInteger('refunded_amount')->default(0);
            $table->json('remote_data')->nullable();
            $table->timestamps();
            $table->unique(['checkout_attempt_id', 'payment_id'], 'ha_payment_unique');
            $table->index(['order_id', 'state']);
        });

        Schema::create('helloasso_webhook_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('payload_hash', 64)->unique();
            $table->string('channel_code');
            $table->string('event_type')->nullable();
            $table->string('status')->default('received');
            $table->timestamps();
        });

        Schema::create('helloasso_refund_operations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_id');
            $table->string('remote_refund_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('amount');
            $table->string('state');
            $table->unsignedInteger('local_refund_id')->nullable();
            $table->timestamps();
            $table->unique(['payment_id', 'remote_refund_id'], 'ha_refund_operation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helloasso_refund_operations');
        Schema::dropIfExists('helloasso_webhook_receipts');
        Schema::dropIfExists('helloasso_payments');
        Schema::dropIfExists('helloasso_checkout_attempts');
        Schema::dropIfExists('helloasso_connections');
    }
};
