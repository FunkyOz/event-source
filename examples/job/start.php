<?php

declare(strict_types=1);

use FunkyOz\EventSource\Examples\Job\JobStore;

require __DIR__ . '/../bootstrap.php';

if ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? null)) {
    http_response_code(405);
    exit;
}

header('Content-Type: application/json');
echo json_encode(['id' => (new JobStore(exampleDatabase()))->start(isFastMode() ? 1 : 10)]);
