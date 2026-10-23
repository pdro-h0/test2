<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Application\Port\MessageRepository;
use App\Domain\Batch;
use App\Domain\Message;
use RuntimeException;

final class FailingMessageRepository implements MessageRepository {
    public function __construct(private readonly MessageRepository $inner) {
    }

    public function find(int $id): ?Message {
        return $this->inner->find($id);
    }

    /** @return array{message: Message, batch: Batch}|null */
    public function findWithBatch(int $id): ?array {
        return $this->inner->findWithBatch($id);
    }

    public function save(Message $message): void {
        throw new RuntimeException('database unavailable');
    }

    /** @return array<string, int> */
    public function countByStatus(?int $clientId = null): array {
        return $this->inner->countByStatus($clientId);
    }
}
