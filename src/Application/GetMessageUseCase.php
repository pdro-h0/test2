<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Port\MessageRepository;
use App\Domain\Client;
use App\Domain\Exception\NotFoundException;

final class GetMessageUseCase {
    public function __construct(private readonly MessageRepository $messages) {
    }

    /** @return array{status: string, attempts: int, last_error: ?string, created_at: string, sent_at: ?string} */
    public function run(Client $client, int $id): array {
        $message = $this->messages->find($id);

        if ($message === null || $message->clientId !== $client->id) {
            throw new NotFoundException('message not found');
        }

        return [
            'status' => $message->getStatus()->value,
            'attempts' => $message->getAttempts(),
            'last_error' => $message->getLastError(),
            'created_at' => $message->createdAt->format(DATE_ATOM),
            'sent_at' => $message->getSentAt()?->format(DATE_ATOM),
        ];
    }
}
