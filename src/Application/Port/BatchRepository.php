<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Batch;

interface BatchRepository {
    /**
     * Batch, messages and outbox rows are written in the same transaction.
     *
     * @param list<string> $recipients
     * @return array{batch_id: int, message_ids: list<int>}
     */
    public function save(Batch $batch, array $recipients): array;
}
