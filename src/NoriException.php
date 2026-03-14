<?php

declare(strict_types=1);

namespace Nori;

class NoriException extends \RuntimeException
{
    public function __construct(
        string $message = "",
        public readonly int $statusCode = 0,
        public readonly ?string $errorCode = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
