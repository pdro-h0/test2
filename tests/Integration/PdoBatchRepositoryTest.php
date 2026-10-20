<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Batch;
use App\Domain\Client;
use App\Infrastructure\Persistence\MigrationRunner;
use App\Infrastructure\Persistence\PdoBatchRepository;
use App\Infrastructure\Persistence\PdoClientRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class PdoBatchRepositoryTest extends TestCase
{
    private PDO $pdo;
    private string $schema;
    private PdoBatchRepository $repository;
    private int $clientId;

    protected function setUp(): void{
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

        $this->schema = 'batch_test_' . bin2hex(random_bytes(4));
        $this->pdo->exec('CREATE SCHEMA ' . $this->schema);
        $this->pdo->exec('SET search_path TO ' . $this->schema);

        (new MigrationRunner($this->pdo, __DIR__ . '/../../migrations'))->run();

        $this->repository = new PdoBatchRepository($this->pdo);
        $this->clientId = (new PdoClientRepository($this->pdo))->create(new Client(
            id: null,
            name: 'Loja',
            apiKeyHash: hash('sha256', 'chave-secreta'),
            fromEmail: 'loja@exemplo.com',
            fromName: 'Loja',
            rateLimitPerSec: 10,
            isActive: true,
        ));
    }

    protected function tearDown(): void{
        if (isset($this->schema)) {
            $this->pdo->exec('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
    }

    public function test_it_saves_batch_messages_and_outbox_in_one_transaction(): void{
        $result = $this->repository->save($this->batch(), ['a@exemplo.com', 'b@exemplo.com', 'c@exemplo.com']);

        self::assertSame(1, $this->countRows('batches'));
        self::assertSame('ABC-1', $this->scalar('SELECT idempotency_key FROM batches WHERE id = ?', [$result['batch_id']]));
        self::assertSame('loja@exemplo.com', $this->scalar('SELECT from_email FROM batches WHERE id = ?', [$result['batch_id']]));
        self::assertSame('Olá', $this->scalar('SELECT text FROM batches WHERE id = ?', [$result['batch_id']]));

        self::assertSame(3, $this->countRows('messages'));
        self::assertSame(
            ['a@exemplo.com', 'b@exemplo.com', 'c@exemplo.com'],
            $this->column('SELECT recipient FROM messages WHERE batch_id = ? ORDER BY id', [$result['batch_id']]),
        );
        self::assertSame(
            3,
            $this->scalar('SELECT count(*) FROM messages WHERE batch_id = ? AND status = ? AND attempts = 0', [$result['batch_id'], 'queued']),
        );
        self::assertSame(3, $this->countRows('outbox'));
        self::assertSame(3, $this->scalar('SELECT count(*) FROM outbox WHERE published_at IS NULL', []));

        self::assertSame(
            array_map(intval(...), $this->column('SELECT id FROM messages WHERE batch_id = ? ORDER BY id', [$result['batch_id']])),
            $result['message_ids'],
        );
    }

    public function test_it_rolls_back_everything_when_a_message_fails(): void{
        try {
            $this->repository->save($this->batch(), ['a@exemplo.com', 'b@exemplo.com', '']);
            self::fail('Expected the empty recipient to violate the check constraint.');
        } catch (PDOException) {
            // the check constraint on messages.recipient is the failure this test forces
        }

        self::assertSame(0, $this->countRows('batches'));
        self::assertSame(0, $this->countRows('messages'));
        self::assertSame(0, $this->countRows('outbox'));
    }

    public function test_it_finds_a_batch_by_idempotency_key_with_recipients_and_message_ids(): void{
        $saved = $this->repository->save($this->batch(), ['a@exemplo.com', 'b@exemplo.com']);

        $found = $this->repository->findByIdempotencyKey($this->clientId, 'ABC-1');

        self::assertNotNull($found);
        self::assertSame($saved['batch_id'], $found['batch_id']);
        self::assertSame('Seu pedido saiu', $found['subject']);
        self::assertSame('<p>Olá</p>', $found['html']);
        self::assertSame('Olá', $found['text']);
        self::assertSame('loja@exemplo.com', $found['from_email']);
        self::assertSame(['a@exemplo.com', 'b@exemplo.com'], $found['recipients']);
        self::assertSame($saved['message_ids'], $found['message_ids']);
    }

    public function test_it_returns_null_for_an_unknown_key_or_another_client(): void{
        $this->repository->save($this->batch(), ['a@exemplo.com']);

        self::assertNull($this->repository->findByIdempotencyKey($this->clientId, 'OUTRA-CHAVE'));

        $otherClientId = (new PdoClientRepository($this->pdo))->create(new Client(
            id: null,
            name: 'Outra',
            apiKeyHash: hash('sha256', 'outra-chave'),
            fromEmail: 'outra@exemplo.com',
            fromName: null,
            rateLimitPerSec: 10,
            isActive: true,
        ));

        self::assertNull($this->repository->findByIdempotencyKey($otherClientId, 'ABC-1'));
    }

    private function batch(): Batch{
        return new Batch(
            id: null,
            clientId: $this->clientId,
            idempotencyKey: 'ABC-1',
            subject: 'Seu pedido saiu',
            html: '<p>Olá</p>',
            text: 'Olá',
            fromEmail: 'loja@exemplo.com',
        );
    }

    private function countRows(string $table): int{
        return (int) $this->scalar('SELECT count(*) FROM ' . $table, []);
    }

    /** @param list<int|string|null> $params */
    private function scalar(string $sql, array $params): int|string|null{
        $statement = $this->pdo->prepare($sql);
        self::assertNotFalse($statement);
        $statement->execute($params);

        $value = $statement->fetchColumn();
        self::assertNotFalse($value);

        return $value;
    }

    /**
     * @param list<int|string|null> $params
     * @return list<int|string|null>
     */
    private function column(string $sql, array $params): array{
        $statement = $this->pdo->prepare($sql);
        self::assertNotFalse($statement);
        $statement->execute($params);

        return array_values($statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private static function env(string $key): string{
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
