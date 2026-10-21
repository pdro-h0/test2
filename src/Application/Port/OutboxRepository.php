<?php

declare(strict_types=1);

namespace App\Application\Port;

interface OutboxRepository {
    /**
     * Locks unpublished rows with FOR UPDATE SKIP LOCKED.
     * Must run inside the caller's transaction, which commits after the publish confirm.
     *
     * @return list<array{id: int, message_id: int}>
     */
    public function lockPending(int $limit): array;

    public function markPublished(int $id): void;
}
