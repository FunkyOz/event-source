<?php

declare(strict_types=1);

namespace FunkyOz\EventSource\Examples\Job;

/**
 * Jobs stored in a database. In a real application a queue worker would run the job and save its progress; here
 * the progress is computed from the time the job started
 */
final readonly class JobStore
{
    public function __construct(private \PDO $database)
    {
        $database->exec('CREATE TABLE IF NOT EXISTS jobs (id TEXT PRIMARY KEY, started_at REAL NOT NULL, duration REAL NOT NULL)');
    }

    public function start(float $duration): string
    {
        $id = bin2hex(random_bytes(8));
        $this->database
            ->prepare('INSERT INTO jobs (id, started_at, duration) VALUES (:id, :started_at, :duration)')
            ->execute(['id' => $id, 'started_at' => microtime(true), 'duration' => $duration]);

        return $id;
    }

    /**
     * @return int|null The progress in percent, or null for an unknown job
     */
    public function progress(string $id): ?int
    {
        $find = $this->database->prepare('SELECT started_at, duration FROM jobs WHERE id = :id');
        $find->execute(['id' => $id]);
        $job = $find->fetch();
        if (!is_array($job) || !is_numeric($job['started_at']) || !is_numeric($job['duration'])) {
            return null;
        }

        return min(100, (int) floor((microtime(true) - (float) $job['started_at']) / (float) $job['duration'] * 100));
    }
}
