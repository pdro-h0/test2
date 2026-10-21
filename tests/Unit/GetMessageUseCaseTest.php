<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Application\GetMessageUseCase;
use App\Domain\Client;
use App\Domain\Exception\NotFoundException;
use App\Domain\Message;
use App\Tests\Fake\InMemoryMessageRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class GetMessageUseCaseTest extends TestCase {
    public function test_it_returns_the_message_status_fields(): void {
        $repository = new InMemoryMessageRepository();
        $repository->messages[10] = $this->message(clientId: 1);

        $result = (new GetMessageUseCase($repository))->run($this->client(1), 10);

        self::assertSame([
            'status' => 'queued',
            'attempts' => 0,
            'last_error' => null,
            'created_at' => '2026-10-21T12:00:00+00:00',
            'sent_at' => null,
        ], $result);
    }

    public function test_it_returns_the_sent_at_of_a_sent_message(): void {
        $message = $this->message(clientId: 1);
        $message->markSending();
        $message->markSent(new DateTimeImmutable('2026-10-21T12:05:00+00:00'));

        $repository = new InMemoryMessageRepository();
        $repository->messages[10] = $message;

        $result = (new GetMessageUseCase($repository))->run($this->client(1), 10);

        self::assertSame('sent', $result['status']);
        self::assertSame('2026-10-21T12:05:00+00:00', $result['sent_at']);
    }

    public function test_it_throws_not_found_for_an_unknown_id(): void {
        $this->expectException(NotFoundException::class);

        (new GetMessageUseCase(new InMemoryMessageRepository()))->run($this->client(1), 999);
    }

    public function test_it_throws_not_found_for_another_clients_message(): void {
        $repository = new InMemoryMessageRepository();
        $repository->messages[10] = $this->message(clientId: 1);

        $this->expectException(NotFoundException::class);

        (new GetMessageUseCase($repository))->run($this->client(2), 10);
    }

    private function message(int $clientId): Message {
        return new Message(
            id: 10,
            batchId: 1,
            clientId: $clientId,
            recipient: 'a@exemplo.com',
            createdAt: new DateTimeImmutable('2026-10-21T12:00:00+00:00'),
        );
    }

    private function client(int $id): Client {
        return new Client(
            id: $id,
            name: 'Loja',
            apiKeyHash: hash('sha256', 'chave'),
            fromEmail: 'loja@exemplo.com',
            fromName: null,
            rateLimitPerSec: 10,
            isActive: true,
        );
    }
}
