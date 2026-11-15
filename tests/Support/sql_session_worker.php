<?php

/**
 * Worker for Tests\Feature\Database\DeadlockCyclesTest: one separate MySQL session that runs a fixed list of steps
 * (SQL statements and pauses) and prints what happened as JSON. Plain PDO, so it starts fast and holds its locks for
 * exactly the steps given. A failing statement rolls back and is reported with its driver error code.
 *
 * Usage: php sql_session_worker.php <steps_json_file>; DB_* environment variables as for the app.
 * Steps: [["sql", "<statement>", [bindings]], ["sleep", <seconds>], …]
 */
$steps = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '3306', getenv('DB_DATABASE')),
    getenv('DB_USERNAME') ?: 'root',
    getenv('DB_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$pdo->exec('set session innodb_lock_wait_timeout = 10');

$done = 0;
try {
    foreach ($steps as $step) {
        if ($step[0] === 'sleep') {
            usleep((int) ($step[1] * 1_000_000));
        } else {
            $statement = $pdo->prepare($step[1]);
            $statement->execute($step[2] ?? []);
        }
        $done++;
    }
    echo json_encode(['ok' => true, 'steps' => $done]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['ok' => false, 'steps' => $done, 'code' => (int) ($e->errorInfo[1] ?? 0), 'message' => $e->getMessage()]);
}
