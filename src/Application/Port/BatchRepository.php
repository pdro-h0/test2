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
    public function save(Batch $batch, array $recipients, string $payloadHash): array;

    /**
     * @return array{
     *     batch_id: int,
     *     subject: string,
     *     html: string,
     *     text: string,
     *     from_email: string,
     *     payload_hash: ?string,
     *     html_purged_at: ?string,
     *     recipients: list<string>,
     *     message_ids: list<int>,
     * }|null
     */
    public function findByIdempotencyKey(int $clientId, string $idempotencyKey): ?array;
}
