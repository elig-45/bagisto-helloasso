<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helloasso_checkout_attempts', function (Blueprint $table) {
            $table->string('environment', 20)->default('unknown');
            $table->string('auth_mode', 20)->default('unknown');
            $table->string('cart_fingerprint', 64)->nullable();
            $table->boolean('fulfillment_ready')->default(false);
            $table->boolean('future_payments_canceled')->default(false);
        });

        DB::table('helloasso_checkout_attempts')->orderBy('id')->chunkById(100, function ($attempts) {
            foreach ($attempts as $attempt) {
                $connection = DB::table('helloasso_connections')
                    ->where('channel_code', $attempt->channel_code)
                    ->first();

                $connection ??= DB::table('helloasso_connections')
                    ->where('channel_code', 'global')
                    ->first();

                if (
                    $connection
                    && $connection->organization_slug === $attempt->organization_slug
                    && in_array($connection->environment, ['sandbox', 'production'], true)
                ) {
                    DB::table('helloasso_checkout_attempts')
                        ->where('id', $attempt->id)
                        ->update([
                            'environment' => $connection->environment,
                            'auth_mode' => $connection->auth_mode,
                        ]);
                }
            }
        });

        DB::table('helloasso_checkout_attempts')
            ->where('status', 'abandoned')
            ->whereNull('order_id')
            ->whereNotNull('cart_snapshot')
            ->update(['status' => 'pending']);

        Schema::table('helloasso_refund_operations', function (Blueprint $table) {
            $table->string('request_key', 36)->nullable()->index();
            $table->json('refund_data')->nullable();
        });

        Schema::create('helloasso_refund_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_key', 36)->unique();
            $table->foreignId('checkout_attempt_id')->constrained('helloasso_checkout_attempts')->cascadeOnDelete();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('amount');
            $table->json('refund_data');
            $table->string('comment', 500);
            $table->string('state', 32)->default('submitting');
            $table->unsignedInteger('local_refund_id')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helloasso_refund_requests');

        Schema::table('helloasso_refund_operations', function (Blueprint $table) {
            $table->dropIndex(['request_key']);
            $table->dropColumn(['request_key', 'refund_data']);
        });

        Schema::table('helloasso_checkout_attempts', function (Blueprint $table) {
            $table->dropColumn([
                'environment',
                'auth_mode',
                'cart_fingerprint',
                'fulfillment_ready',
                'future_payments_canceled',
            ]);
        });
    }
};
