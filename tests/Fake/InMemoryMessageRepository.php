<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Application\Port\MessageRepository;
use App\Domain\Batch;
use App\Domain\Message;

final class InMemoryMessageRepository implements MessageRepository {
    /** @var array<int, Message> */
    public array $messages = [];

    /** @var array<int, Batch> keyed by message id */
    public array $batches = [];

    public function find(int $id): ?Message {
        return $this->messages[$id] ?? null;
    }

    /** @return array{message: Message, batch: Batch}|null */
    public function findWithBatch(int $id): ?array {
        $message = $this->messages[$id] ?? null;

        if ($message === null || !isset($this->batches[$id])) {
            return null;
        }

        return ['message' => $message, 'batch' => $this->batches[$id]];
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
