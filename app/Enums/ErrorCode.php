<?php

namespace App\Enums;

/**
 * Stable, machine-readable error codes. The apps switch on these, never on the message.
 * Add new codes in the slice that first needs them (see docs/03-api-conventions.md).
 */
enum ErrorCode: string
{
    case ValidationFailed = 'VALIDATION_FAILED';
    case Unauthenticated = 'UNAUTHENTICATED';
    case TokenExpired = 'TOKEN_EXPIRED';
    case Forbidden = 'FORBIDDEN';
    case AccountSuspended = 'ACCOUNT_SUSPENDED';
    case NotFound = 'NOT_FOUND';
    case MethodNotAllowed = 'METHOD_NOT_ALLOWED';
    case InvalidState = 'INVALID_STATE';
    case NotConfigured = 'NOT_CONFIGURED';
    case TooManyAttempts = 'TOO_MANY_ATTEMPTS';
    case ServerError = 'SERVER_ERROR';

    public function status(): int
    {
        return match ($this) {
            self::ValidationFailed => 422,
            self::Unauthenticated, self::TokenExpired => 401,
            self::Forbidden, self::AccountSuspended => 403,
            self::NotFound => 404,
            self::MethodNotAllowed => 405,
            self::InvalidState, self::NotConfigured => 409,
            self::TooManyAttempts => 429,
            self::ServerError => 500,
        };
    }

    public function message(): string
    {
        return __('errors.'.$this->value);
    }

    public static function fromStatus(int $status): self
    {
        return match ($status) {
            401 => self::Unauthenticated,
            403 => self::Forbidden,
            404 => self::NotFound,
            405 => self::MethodNotAllowed,
            409 => self::InvalidState,
            422 => self::ValidationFailed,
            429 => self::TooManyAttempts,
            default => self::ServerError,
        };
    }
}
