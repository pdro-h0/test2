<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Application\Port\MessageRepository;
use App\Domain\Message;

final class InMemoryMessageRepository implements MessageRepository {
    /** @var array<int, Message> */
    public array $messages = [];

    public function find(int $id): ?Message {
        return $this->messages[$id] ?? null;
    }

    /** @return array{message: Message, batch: \App\Domain\Batch}|null */
    public function findWithBatch(int $id): ?array {
        return null;
    }

    public function save(Message $message): void {
        if ($message->id !== null) {
            $this->messages[$message->id] = $message;
        }
    }

    /** @return array<string, int> */
    public function countByStatus(?int $clientId = null): array {
        return [];
    }
}
