<?php

namespace App\Services\ServiceRequests;

use RuntimeException;

/**
 * Internal signal: a parallel create with the same Idempotency-Key committed first. Never rendered.
 */
class IdempotencyRace extends RuntimeException {}
