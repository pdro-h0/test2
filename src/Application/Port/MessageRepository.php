<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Batch;
use App\Domain\Message;

interface MessageRepository {
    public function find(int $id): ?Message;

    /** @return array{message: Message, batch: Batch}|null */
    public function findWithBatch(int $id): ?array;

    public function save(Message $message): void;

    /** @return array<string, int> every status, zero when there is no row */
    public function countByStatus(?int $clientId = null): array;
}
