<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Batch;
use App\Domain\Client;
use App\Domain\MessageStatus;
use App\Infrastructure\Persistence\MigrationRunner;
use App\Infrastructure\Persistence\PdoBatchRepository;
use App\Infrastructure\Persistence\PdoClientRepository;
use App\Infrastructure\Persistence\PdoMessageRepository;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoMessageRepositoryTest extends TestCase {
    private PDO $pdo;
    private string $schema;
    private PdoMessageRepository $repository;
    private int $clientId;

    protected function setUp(): void {
        $this->pdo = new PDO(
            sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                self::env('POSTGRES_HOST'),
                self::env('POSTGRES_PORT'),
                self::env('POSTGRES_DB'),
            ),
            self::env('POSTGRES_USER'),
            self::env('POSTGRES_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $this->schema = 'message_test_' . bin2hex(random_bytes(4));
        $this->pdo->exec('CREATE SCHEMA ' . $this->schema);
        $this->pdo->exec('SET search_path TO ' . $this->schema);

        (new MigrationRunner($this->pdo, __DIR__ . '/../../migrations'))->run();

        $this->repository = new PdoMessageRepository($this->pdo);
        $this->clientId = $this->createClient('Loja', 'loja@exemplo.com');
    }

    protected function tearDown(): void {
        if (isset($this->schema)) {
            $this->pdo->exec('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
    }

    public function test_it_finds_a_message_with_its_batch(): void {
        $messageId = $this->saveBatch($this->clientId)[0];

        $found = $this->repository->findWithBatch($messageId);

        self::assertNotNull($found);
        self::assertSame($messageId, $found['message']->id);
        self::assertSame('a@exemplo.com', $found['message']->recipient);
        self::assertSame(MessageStatus::Queued, $found['message']->getStatus());
        self::assertSame(0, $found['message']->getAttempts());
        self::assertSame('Seu pedido saiu', $found['batch']->subject);
        self::assertSame('<p>Olá</p>', $found['batch']->html);
        self::assertSame('Olá', $found['batch']->text);
        self::assertSame('loja@exemplo.com', $found['batch']->fromEmail);
    }

    public function test_it_returns_null_for_an_unknown_message_id(): void {
        self::assertNull($this->repository->find(999999));
        self::assertNull($this->repository->findWithBatch(999999));
    }

    public function test_it_saves_status_attempts_last_error_and_sent_at(): void {
        $messageId = $this->saveBatch($this->clientId)[0];

        $message = $this->repository->find($messageId);
        self::assertNotNull($message);
        $message->markSending();
        $this->repository->save($message);

        $reloaded = $this->repository->find($messageId);
        self::assertNotNull($reloaded);
        self::assertSame(MessageStatus::Sending, $reloaded->getStatus());

        $reloaded->markForRetry('timeout');
        $this->repository->save($reloaded);

        $reloaded = $this->repository->find($messageId);
        self::assertNotNull($reloaded);
        self::assertSame(MessageStatus::Queued, $reloaded->getStatus());
        self::assertSame(1, $reloaded->getAttempts());
        self::assertSame('timeout', $reloaded->getLastError());
        self::assertNull($reloaded->getSentAt());

        $reloaded->markSending();
        $reloaded->markSent(new DateTimeImmutable('2026-01-02 03:04:05+00:00'));
        $this->repository->save($reloaded);

        $reloaded = $this->repository->find($messageId);
        self::assertNotNull($reloaded);
        self::assertSame(MessageStatus::Sent, $reloaded->getStatus());
        self::assertSame(1, $reloaded->getAttempts());
        self::assertSame('timeout', $reloaded->getLastError());
        self::assertSame('2026-01-02T03:04:05+00:00', $reloaded->getSentAt()?->format(DATE_ATOM));
    }

    public function test_it_counts_messages_by_status_globally_and_per_client(): void {
        $messageIds = $this->saveBatch($this->clientId);
        $otherClientId = $this->createClient('Outra', 'outra@exemplo.com');
        $this->saveBatch($otherClientId);

        $sent = $this->repository->find($messageIds[0]);
        self::assertNotNull($sent);
        $sent->markSending();
        $sent->markSent(new DateTimeImmutable());
        $this->repository->save($sent);

        $failed = $this->repository->find($messageIds[1]);
        self::assertNotNull($failed);
        $failed->markSending();
        $failed->markFailed('550 mailbox unavailable');
        $this->repository->save($failed);

        self::assertSame(
            ['queued' => 2, 'sending' => 0, 'sent' => 1, 'failed' => 1],
            $this->repository->countByStatus(),
        );
        self::assertSame(
            ['queued' => 0, 'sending' => 0, 'sent' => 1, 'failed' => 1],
            $this->repository->countByStatus($this->clientId),
        );
        self::assertSame(
            ['queued' => 2, 'sending' => 0, 'sent' => 0, 'failed' => 0],
            $this->repository->countByStatus($otherClientId),
        );
    }

    private function createClient(string $name, string $fromEmail): int {
        return (new PdoClientRepository($this->pdo))->create(new Client(
            id: null,
            name: $name,
            apiKeyHash: hash('sha256', $name),
            fromEmail: $fromEmail,
            fromName: $name,
            rateLimitPerSec: 10,
            isActive: true,
        ));
    }

    /** @return list<int> */
    private function saveBatch(int $clientId): array {
        return (new PdoBatchRepository($this->pdo))->save(
            new Batch(
                id: null,
                clientId: $clientId,
                idempotencyKey: null,
                subject: 'Seu pedido saiu',
                html: '<p>Olá</p>',
                text: 'Olá',
                fromEmail: 'loja@exemplo.com',
            ),
            ['a@exemplo.com', 'b@exemplo.com'],
        )['message_ids'];
    }

    private static function env(string $key): string {
        $value = getenv($key);

        if ($value === false || $value === '') {
            self::markTestSkipped(sprintf(
                'Postgres not configured (%s missing). Run: docker compose up -d postgres && set -a; . ./.env; set +a',
                $key,
            ));
        }

        return $value;
    }
}
