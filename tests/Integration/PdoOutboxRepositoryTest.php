<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Batch;
use App\Domain\Client;
use App\Infrastructure\Persistence\MigrationRunner;
use App\Infrastructure\Persistence\PdoBatchRepository;
use App\Infrastructure\Persistence\PdoClientRepository;
use App\Infrastructure\Persistence\PdoOutboxRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoOutboxRepositoryTest extends TestCase {
    private PDO $pdo;
    private string $schema;
    private PdoOutboxRepository $repository;

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

        $this->schema = 'outbox_test_' . bin2hex(random_bytes(4));
        $this->pdo->exec('CREATE SCHEMA ' . $this->schema);
        $this->pdo->exec('SET search_path TO ' . $this->schema);

        (new MigrationRunner($this->pdo, __DIR__ . '/../../migrations'))->run();

        $this->repository = new PdoOutboxRepository($this->pdo);
    }

    protected function tearDown(): void {
        if (isset($this->schema)) {
            $this->pdo->exec('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
    }

    public function test_it_locks_pending_rows_and_marks_them_published(): void {
        $messageIds = $this->saveBatch();

        $this->pdo->beginTransaction();
        $pending = $this->repository->lockPending(10);
        self::assertCount(2, $pending);
        self::assertSame($messageIds, array_column($pending, 'message_id'));

        $this->repository->markPublished($pending[0]['id']);
        $this->pdo->commit();

        self::assertNotNull($this->scalar('SELECT published_at FROM outbox WHERE id = ?', [$pending[0]['id']]));
        self::assertNull($this->scalar('SELECT published_at FROM outbox WHERE id = ?', [$pending[1]['id']]));

        $this->pdo->beginTransaction();
        $remaining = $this->repository->lockPending(10);
        $this->pdo->commit();

        self::assertSame([$pending[1]], $remaining);
    }

    public function test_it_skips_rows_locked_by_another_transaction(): void {
        $this->saveBatch();

        $other = new PDO(
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
        $other->exec('SET search_path TO ' . $this->schema);

        $this->pdo->beginTransaction();
        $locked = $this->repository->lockPending(10);
        self::assertCount(2, $locked);

        $other->beginTransaction();
        $otherPending = (new PdoOutboxRepository($other))->lockPending(10);
        $other->rollBack();

        self::assertSame([], $otherPending);

        $this->pdo->rollBack();
    }

    /** @return list<int> */
    private function saveBatch(): array {
        $clientId = (new PdoClientRepository($this->pdo))->create(new Client(
            id: null,
            name: 'Loja',
            apiKeyHash: hash('sha256', 'chave-secreta'),
            fromEmail: 'loja@exemplo.com',
            fromName: 'Loja',
            rateLimitPerSec: 10,
            isActive: true,
        ));

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

    /** @param list<int|string|null> $params */
    private function scalar(string $sql, array $params): int|string|null {
        $statement = $this->pdo->prepare($sql);
        self::assertNotFalse($statement);
        $statement->execute($params);

        $value = $statement->fetchColumn();
        self::assertNotFalse($value);

        return $value;
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
