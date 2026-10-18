<?php

namespace Elig45\BagistoHelloAsso\Console\Commands;

use Elig45\BagistoHelloAsso\Models\CheckoutAttempt;
use Elig45\BagistoHelloAsso\Models\Connection;
use Elig45\BagistoHelloAsso\Services\OrderSettlementService;
use Elig45\BagistoHelloAsso\Services\RefundSyncService;
use Illuminate\Console\Command;

class ReconcileCommand extends Command
{
    protected $signature = 'helloasso:reconcile {--limit=100}';

    protected $description = 'Reconcile pending HelloAsso checkouts and refunds';

    public function handle(OrderSettlementService $settlement, RefundSyncService $refunds): int
    {
        $attempts = CheckoutAttempt::where(function ($query) {
            $query->where(function ($query) {
                $query->whereNotIn('status', ['paid', 'abandoned', 'canceled', 'metadata_mismatch', 'amount_mismatch'])
                    ->where(function ($query) {
                        $query->whereNull('last_checked_at')->orWhere('last_checked_at', '<', now()->subMinutes(4));
                    });
            })->orWhere(function ($query) {
                $query->whereNotNull('order_id')
                    ->where(function ($query) {
                        $query->whereNull('last_refund_checked_at')
                            ->orWhere('last_refund_checked_at', '<', now()->subDay());
                    });
            });
        })
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        foreach ($attempts as $attempt) {
            $connection = Connection::where('channel_code', $attempt->channel_code)->first();

            if (! $connection) {
                continue;
            }

            try {
                if ($attempt->created_at->lt(now()->subMinutes(45)) && ! $attempt->order_id && ! $attempt->remote_order_id) {
                    $attempt->update(['status' => 'abandoned', 'cart_snapshot' => null]);

                    continue;
                }

                if (! in_array($attempt->status, ['paid', 'canceled', 'abandoned', 'metadata_mismatch', 'amount_mismatch'], true)) {
                    $settlement->reconcile($attempt, $connection);
                }

                if ($attempt->order_id) {
                    $refunds->syncOrder($attempt->order_id, $connection);
                    $attempt->update(['last_refund_checked_at' => now()]);
                }
            } catch (\Throwable $exception) {
                report($exception);
                $this->warn('A HelloAsso attempt could not be reconciled.');
            }
        }

        return self::SUCCESS;
    }
}
