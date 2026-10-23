<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * What the controller needs from your storage: implement it with Doctrine, PDO…
 */
interface NotificationRepository
{
    /**
     * @return list<array{id: int, title: string}> The notifications of the user after the given id, oldest first
     */
    public function newerThan(int $userId, int $afterId, int $limit): array;

    public function latestId(int $userId): int;
}
