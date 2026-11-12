<?php

/**
 * Worker for Tests\Feature\Database\IdempotencyConcurrencyTest: one separate PHP process (its own MySQL session) that
 * waits for a shared start time, then calls ServiceRequestCreator::create() once and prints the outcome as JSON.
 *
 * Usage: php create_request_worker.php <client_id> <idempotency_key> <body_json_file> <start_unix_float>
 */

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\ServiceRequests\ServiceRequestCreator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $clientId, $key, $bodyFile, $startAt] = $argv;
$client = User::query()->findOrFail((int) $clientId);
$body = json_decode(file_get_contents($bodyFile), true, flags: JSON_THROW_ON_ERROR);
$creator = $app->make(ServiceRequestCreator::class);

// Every worker is booted before the start time, so the create calls overlap.
while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    [$request, $created] = $creator->create($client, $key, $body);
    echo json_encode(['id' => $request->id, 'created' => $created]);
} catch (ApiException $e) {
    echo json_encode(['error' => $e->errorCode->value]);
} catch (ValidationException $e) {
    echo json_encode(['error' => 'VALIDATION_FAILED', 'errors' => $e->errors()]);
}
