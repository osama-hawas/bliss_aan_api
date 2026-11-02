<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;
use RuntimeException;

/**
 * Throw from services to end a request with a specific error code.
 * Rendered into the error envelope by bootstrap/app.php.
 */
class ApiException extends RuntimeException
{
    public function __construct(
        public readonly ErrorCode $errorCode,
        ?string $message = null,
        public readonly array $errors = [],
        public readonly ?int $status = null,
    ) {
        parent::__construct($message ?? $errorCode->message());
    }

    public function status(): int
    {
        return $this->status ?? $this->errorCode->status();
    }
}
