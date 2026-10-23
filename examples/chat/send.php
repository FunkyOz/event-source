<?php

declare(strict_types=1);

/*
 * Messages are posted with a normal request: Server-Sent Events only go from the server to the browser.
 */

use FunkyOz\EventSource\Examples\Chat\ChatStream;

require __DIR__ . '/../bootstrap.php';

if ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? null)) {
    http_response_code(405);
    exit;
}

try {
    $author = $_POST['author'] ?? '';
    $text = $_POST['text'] ?? '';
    (new ChatStream(exampleDatabase()))->post(is_string($author) ? $author : '', is_string($text) ? $text : '');
    http_response_code(204);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo $e->getMessage();
}
