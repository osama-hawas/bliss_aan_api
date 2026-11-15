<?php

namespace App\Services\Availability;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use Illuminate\Contracts\Debug\ShouldntReport;

/**
 * `500 SERVER_ERROR` after the last deadlocked attempt (D98). DeadlockRetry already logged it once with the
 * operation, attempts and ids, so the exception handler does not report it a second time.
 */
class DeadlockRetriesExhausted extends ApiException implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct(ErrorCode::ServerError);
    }
}
