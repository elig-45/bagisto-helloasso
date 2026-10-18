<?php

namespace Elig45\BagistoHelloAsso\Services;

use RuntimeException;

class HelloAssoApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly array $responseData = [],
    ) {
        parent::__construct($message);
    }
}
