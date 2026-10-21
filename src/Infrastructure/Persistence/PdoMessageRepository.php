<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Port\MessageRepository;
use App\Domain\Batch;
use App\Domain\Message;
use App\Domain\MessageStatus;
use DateTimeImmutable;
use LogicException;
use PDO;

final class PdoMessageRepository implements MessageRepository {
    private const SELECT_WITH_BATCH = 'SELECT m.id, m.batch_id, m.client_id, m.recipient, m.status, m.attempts,
               m.last_error, m.created_at, m.sent_at,
               b.subject, b.html, b.text, b.from_email, b.idempotency_key
        FROM messages m
        JOIN batches b ON b.id = m.batch_id
        WHERE m.id = ?';

    public function __construct(private readonly PDO $pdo) {
    }

    // shortcut: find loads the batch html too, split the query if GET /messages/{id} gets hot
    public function find(int $id): ?Message {
        return $this->findWithBatch($id)['message'] ?? null;
    }

    public function findWithBatch(int $id): ?array {
        $statement = $this->pdo->prepare(self::SELECT_WITH_BATCH);
        $statement->execute([$id]);

        /** @var array{
         *     id: int|string,
         *     batch_id: int|string,
         *     client_id: int|string,
         *     recipient: string,
         *     status: string,
         *     attempts: int|string,
         *     last_error: string|null,
         *     created_at: string,
         *     sent_at: string|null,
         *     subject: string,
         *     html: string,
         *     text: string,
         *     from_email: string,
         *     idempotency_key: string|null,
         * }|false $row
         */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return ['message' => $this->mapMessage($row), 'batch' => $this->mapBatch($row)];
    }

    public function save(Message $message): void {
        $id = $message->id ?? throw new LogicException('message must be persisted');

        $statement = $this->pdo->prepare(
            'UPDATE messages SET status = ?, attempts = ?, last_error = ?, sent_at = ? WHERE id = ?'
        );
        $statement->execute([
            $message->getStatus()->value,
            $message->getAttempts(),
            $message->getLastError(),
            $message->getSentAt()?->format(DATE_ATOM),
            $id,
        ]);
    }

    public function countByStatus(?int $clientId = null): array {
        $statement = $this->pdo->prepare(
            'SELECT status, count(*) AS total
             FROM messages
             WHERE client_id = COALESCE(CAST(:client_id AS bigint), client_id)
             GROUP BY status'
        );
        $statement->execute([':client_id' => $clientId]);

        /** @var list<array{status: string, total: int|string}> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        /** @var array<string, int> $counts */
        $counts = array_fill_keys(array_column(MessageStatus::cases(), 'value'), 0);

        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /** @param array{id: int|string, batch_id: int|string, client_id: int|string, recipient: string, status: string, attempts: int|string, last_error: string|null, created_at: string, sent_at: string|null} $row */
    private function mapMessage(array $row): Message {
        return new Message(
            id: (int) $row['id'],
            batchId: (int) $row['batch_id'],
            clientId: (int) $row['client_id'],
            recipient: $row['recipient'],
            createdAt: new DateTimeImmutable($row['created_at']),
            status: MessageStatus::from($row['status']),
            attempts: (int) $row['attempts'],
            lastError: $row['last_error'],
            sentAt: $row['sent_at'] === null ? null : new DateTimeImmutable($row['sent_at']),
        );
    }

    /** @param array{batch_id: int|string, client_id: int|string, subject: string, html: string, text: string, from_email: string, idempotency_key: string|null} $row */
    private function mapBatch(array $row): Batch {
        return new Batch(
            id: (int) $row['batch_id'],
            clientId: (int) $row['client_id'],
            idempotencyKey: $row['idempotency_key'],
            subject: $row['subject'],
            html: $row['html'],
            text: $row['text'],
            fromEmail: $row['from_email'],
        );
    }
}
