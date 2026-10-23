<?php

declare(strict_types=1);

/*
 * Shared by the examples: the autoloader, and a small SQLite database for the examples that store data.
 */

require __DIR__ . '/../vendor/autoload.php';

/**
 * The tests run the examples against their own database, and without waiting
 */
function exampleDatabase(): PDO
{
    $path = getenv('EXAMPLES_DATABASE') ?: sys_get_temp_dir() . '/event-source-examples.sqlite';
    $database = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // Lets the streams read while another request writes
    $database->exec('PRAGMA journal_mode = WAL');

    return $database;
}

function isFastMode(): bool
{
    return '1' === getenv('EXAMPLES_FAST');
}
