<?php

namespace App\Services\Availability;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Re-runs a whole unit of work when InnoDB aborted it as a deadlock victim (D98, D100, §10.3). Only MySQL error 1213
 * is retried; lock wait timeouts (1205), other SQL errors and every other exception go through unchanged. Each
 * attempt is a fresh outermost transaction opened by the callback itself; inside an already open transaction the
 * callback runs once. Laravel's `DB::transaction($cb, $attempts)` is not used: it also retries other errors.
 */
class DeadlockRetry
{
    public const ATTEMPTS = 3;

    public const DEADLOCK = 1213;

    /**
     * @template T
     *
     * @param  Closure(): T  $work  the complete sequence of one attempt, opening its own transaction
     * @param  string  $operation  log name, e.g. `service_request.create`
     * @param  array<string, mixed>  $context  log fields (ids only, never personal data)
     * @return T
     */
    public function run(Closure $work, string $operation, array $context = []): mixed
    {
        if (DB::transactionLevel() > 0) {
            return $work();
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                return $work();
            } catch (QueryException $e) {
                if (! self::isDeadlock($e)) {
                    throw $e;
                }
                if ($attempt >= self::ATTEMPTS) {
                    Log::error('Deadlock retries exhausted.', ['operation' => $operation, 'attempts' => $attempt, 'code' => self::DEADLOCK, ...$context]);

                    throw new DeadlockRetriesExhausted;
                }
                Log::warning('Deadlock, retrying.', ['operation' => $operation, 'attempt' => $attempt, 'code' => self::DEADLOCK, ...$context]);
                $this->beforeRetry($attempt + 1);
            }
        }
    }

    /**
     * Runs between two attempts, after the aborted transaction was rolled back. No-op; tests extend it.
     */
    protected function beforeRetry(int $nextAttempt): void {}

    public static function isDeadlock(QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === self::DEADLOCK;
    }
}
