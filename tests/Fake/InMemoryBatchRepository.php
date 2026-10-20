<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Application\Port\BatchRepository;
use App\Domain\Batch;

final class InMemoryBatchRepository implements BatchRepository {
    /**
     * @var list<array{
     *     batch_id: int,
     *     client_id: int,
     *     idempotency_key: ?string,
     *     subject: string,
     *     html: string,
     *     text: string,
     *     from_email: string,
     *     recipients: list<string>,
     *     message_ids: list<int>,
     * }>
     */
    public array $saved = [];

    private int $nextMessageId = 1;

    public function save(Batch $batch, array $recipients): array {
        $batchId = count($this->saved) + 1;
        $messageIds = [];

        for ($index = 0; $index < count($recipients); $index++) {
            $messageIds[] = $this->nextMessageId++;
        }

        $this->saved[] = [
            'batch_id' => $batchId,
            'client_id' => $batch->clientId,
            'idempotency_key' => $batch->idempotencyKey,
            'subject' => $batch->subject,
            'html' => $batch->html,
            'text' => $batch->text,
            'from_email' => $batch->fromEmail,
            'recipients' => $recipients,
            'message_ids' => $messageIds,
        ];

        return ['batch_id' => $batchId, 'message_ids' => $messageIds];
    }

    public function findByIdempotencyKey(int $clientId, string $idempotencyKey): ?array {
        foreach ($this->saved as $row) {
            if ($row['client_id'] !== $clientId || $row['idempotency_key'] !== $idempotencyKey) {
                continue;
            }

            return [
                'batch_id' => $row['batch_id'],
                'subject' => $row['subject'],
                'html' => $row['html'],
                'text' => $row['text'],
                'from_email' => $row['from_email'],
                'recipients' => $row['recipients'],
                'message_ids' => $row['message_ids'],
            ];
        }

        return null;
    }
}
