<?php

namespace Elig45\BagistoHelloAsso\Console\Commands;

use Elig45\BagistoHelloAsso\Models\Connection;
use Elig45\BagistoHelloAsso\Services\AccessTokenService;
use Illuminate\Console\Command;

class RefreshTokensCommand extends Command
{
    protected $signature = 'helloasso:refresh-tokens';

    protected $description = 'Refresh HelloAsso organization tokens before they expire';

    public function handle(AccessTokenService $tokens): int
    {
        foreach (Connection::whereNotNull('refresh_token')->get() as $connection) {
            try {
                $connection->forceFill(['token_expires_at' => now()->subMinute()])->save();
                $tokens->token($connection);
            } catch (\Throwable $exception) {
                report($exception);
                $this->warn('A HelloAsso token could not be refreshed.');
            }
        }

        return self::SUCCESS;
    }
}
