<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Port\OutboxRepository;
use PDO;

final class PdoOutboxRepository implements OutboxRepository {
    public function __construct(private readonly PDO $pdo) {
    }

    public function lockPending(int $limit): array {
        $statement = $this->pdo->prepare(
            'SELECT id, message_id
             FROM outbox
             WHERE published_at IS NULL
             ORDER BY id
             LIMIT ?
             FOR UPDATE SKIP LOCKED'
        );
        $statement->bindValue(1, $limit, PDO::PARAM_INT);
        $statement->execute();

        /** @var list<array{id: int|string, message_id: int|string}> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'message_id' => (int) $row['message_id']],
            $rows,
        );
    }

    public function markPublished(int $id): void {
        $this->pdo
            ->prepare('UPDATE outbox SET published_at = now() WHERE id = ?')
            ->execute([$id]);
    }
}
