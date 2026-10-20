<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Port\BatchRepository;
use App\Domain\Batch;
use PDO;
use Throwable;

final class PdoBatchRepository implements BatchRepository {
    public function __construct(private readonly PDO $pdo) {
    }

    public function save(Batch $batch, array $recipients): array {
        $this->pdo->beginTransaction();

        try {
            $batchId = $this->insertBatch($batch);
            $messageIds = $this->insertMessages($batch, $batchId, $recipients);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();

            throw $exception;
        }

        return ['batch_id' => $batchId, 'message_ids' => $messageIds];
    }

    public function findByIdempotencyKey(int $clientId, string $idempotencyKey): ?array {
        $statement = $this->pdo->prepare(
            'SELECT id, subject, html, text, from_email
             FROM batches
             WHERE client_id = ? AND idempotency_key = ?'
        );
        $statement->execute([$clientId, $idempotencyKey]);

        $batch = $statement->fetch(PDO::FETCH_ASSOC);

        if ($batch === false) return null;

        $batchId = (int) $batch['id'];

        $statement = $this->pdo->prepare('SELECT id, recipient FROM messages WHERE batch_id = ? ORDER BY id');
        $statement->execute([$batchId]);

        $recipients = [];
        $messageIds = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $recipients[] = (string) $row['recipient'];
            $messageIds[] = (int) $row['id'];
        }

        return [
            'batch_id' => $batchId,
            'subject' => (string) $batch['subject'],
            'html' => (string) $batch['html'],
            'text' => (string) $batch['text'],
            'from_email' => (string) $batch['from_email'],
            'recipients' => $recipients,
            'message_ids' => $messageIds,
        ];
    }

    private function insertBatch(Batch $batch): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO batches (client_id, idempotency_key, subject, html, text, from_email)
             VALUES (:client_id, :idempotency_key, :subject, :html, :text, :from_email)
             RETURNING id'
        );
        $statement->execute([
            ':client_id' => $batch->clientId,
            ':idempotency_key' => $batch->idempotencyKey,
            ':subject' => $batch->subject,
            ':html' => $batch->html,
            ':text' => $batch->text,
            ':from_email' => $batch->fromEmail,
        ]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param list<string> $recipients
     * @return list<int>
     */
    private function insertMessages(Batch $batch, int $batchId, array $recipients): array {
        $insertMessage = $this->pdo->prepare(
            'INSERT INTO messages (batch_id, client_id, recipient) VALUES (?, ?, ?) RETURNING id'
        );
        $insertOutbox = $this->pdo->prepare('INSERT INTO outbox (message_id) VALUES (?)');

        $messageIds = [];

        // shortcut: one round trip per message, bulk insert when 1000-recipient posts become slow
        foreach ($recipients as $recipient) {
            $insertMessage->execute([$batchId, $batch->clientId, $recipient]);
            $messageId = (int) $insertMessage->fetchColumn();
            $insertOutbox->execute([$messageId]);
            $messageIds[] = $messageId;
        }

        return $messageIds;
    }
}
