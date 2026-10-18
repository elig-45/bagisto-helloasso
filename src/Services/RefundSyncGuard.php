<?php

namespace Elig45\BagistoHelloAsso\Services;

class RefundSyncGuard
{
    protected int $depth = 0;

    public function isAllowed(): bool
    {
        return $this->depth > 0;
    }

    public function run(callable $callback): mixed
    {
        $this->depth++;

        try {
            return $callback();
        } finally {
            $this->depth--;
        }
    }
}
