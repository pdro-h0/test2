<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Infrastructure\Persistence\MigrationRunner;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    private PDO $pdo;
    private string $schema;

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

        $this->schema = 'schema_test_' . bin2hex(random_bytes(4));
        $this->pdo->exec('CREATE SCHEMA ' . $this->schema);
        $this->pdo->exec('SET search_path TO ' . $this->schema);

        (new MigrationRunner($this->pdo, __DIR__ . '/../../migrations'))->run();
    }

    protected function tearDown(): void{
        if (isset($this->schema)) {
            $this->pdo->exec('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
    }

    public function test_it_round_trips_a_row_in_each_table(): void{
        $clientId = $this->insert(
            'INSERT INTO clients (name, api_key_hash, from_email, from_name, rate_limit_per_sec)
             VALUES (?, ?, ?, ?, ?) RETURNING id',
            ['Loja', str_repeat('a', 64), 'loja@exemplo.com', 'Loja', 10],
        );
        $batchId = $this->insert(
            'INSERT INTO batches (client_id, idempotency_key, subject, html, text, from_email)
             VALUES (?, ?, ?, ?, ?, ?) RETURNING id',
            [$clientId, 'pedido-1', 'Seu pedido saiu', '<p>Olá</p>', 'Olá', 'loja@exemplo.com'],
        );
        $messageId = $this->insert(
            'INSERT INTO messages (batch_id, client_id, recipient) VALUES (?, ?, ?) RETURNING id',
            [$batchId, $clientId, 'a@exemplo.com'],
        );
        $outboxId = $this->insert(
            'INSERT INTO outbox (message_id) VALUES (?) RETURNING id',
            [$messageId],
        );

        self::assertSame('Loja', $this->scalar('SELECT name FROM clients WHERE id = ?', [$clientId]));
        self::assertSame(
            'loja@exemplo.com',
            $this->scalar('SELECT from_email FROM batches WHERE id = ?', [$batchId]),
        );
        self::assertSame('queued', $this->scalar('SELECT status FROM messages WHERE id = ?', [$messageId]));
        self::assertSame(0, $this->scalar('SELECT attempts FROM messages WHERE id = ?', [$messageId]));
        self::assertNull($this->scalar('SELECT published_at FROM outbox WHERE id = ?', [$outboxId]));
    }

    public function test_it_enforces_one_batch_per_idempotency_key(): void{
        $clientA = $this->client('a@exemplo.com');
        $clientB = $this->client('b@exemplo.com');

        $this->batch($clientA, 'same-key');
        $this->batch($clientA, null);
        $this->batch($clientA, null);
        $this->batch($clientB, 'same-key');

        try {
            $this->batch($clientA, 'same-key');
            self::fail('A duplicate idempotency key must be rejected.');
        } catch (PDOException $e) {
            self::assertSame('23505', (string) $e->getCode());
        }
    }

    public function test_it_rejects_a_payload_hash_that_is_not_64_hex_characters(): void {
        $clientId = $this->client('a@exemplo.com');

        try {
            $this->insert(
                'INSERT INTO batches (client_id, subject, html, text, from_email, payload_hash)
                 VALUES (?, ?, ?, ?, ?, ?) RETURNING id',
                [$clientId, 'Assunto', '<p>Olá</p>', 'Olá', 'loja@exemplo.com', 'not-a-hash'],
            );
            self::fail('An invalid payload hash must be rejected.');
        } catch (PDOException $e) {
            self::assertSame('23514', (string) $e->getCode());
        }
    }

    public function test_it_adds_payload_hash_to_an_existing_schema_without_losing_data(): void {
        $schema = 'schema_upgrade_' . bin2hex(random_bytes(4));
        $this->pdo->exec('CREATE SCHEMA ' . $schema);
        $this->pdo->exec('SET search_path TO ' . $schema);

        try {
            $this->pdo->exec(
                'CREATE TABLE schema_migrations (
                    name text PRIMARY KEY,
                    applied_at timestamptz NOT NULL DEFAULT now()
                )'
            );

            foreach (['001_init.sql', '002_rate_limit.sql'] as $name) {
                $sql = file_get_contents(__DIR__ . '/../../migrations/' . $name);
                self::assertNotFalse($sql);
                $this->pdo->exec($sql);
                $this->pdo->prepare('INSERT INTO schema_migrations (name) VALUES (?)')->execute([$name]);
            }

            $clientId = $this->client('a@exemplo.com');
            $batchId = $this->batch($clientId, 'pedido-1');

            $applied = (new MigrationRunner($this->pdo, __DIR__ . '/../../migrations'))->run();

            self::assertSame(['003_payload_hash.sql', '004_outbox_message_id.sql'], $applied);
            self::assertSame('Assunto', $this->scalar('SELECT subject FROM batches WHERE id = ?', [$batchId]));
            self::assertNull($this->scalar('SELECT payload_hash FROM batches WHERE id = ?', [$batchId]));
        } finally {
            $this->pdo->exec('SET search_path TO ' . $this->schema);
            $this->pdo->exec('DROP SCHEMA ' . $schema . ' CASCADE');
        }
    }

    private function client(string $email): int{
        return $this->insert(
            'INSERT INTO clients (name, api_key_hash, from_email, rate_limit_per_sec)
             VALUES (?, ?, ?, ?) RETURNING id',
            ['Cliente', hash('sha256', $email), $email, 10],
        );
    }

    private function batch(int $clientId, ?string $idempotencyKey): int{
        return $this->insert(
            'INSERT INTO batches (client_id, idempotency_key, subject, html, text, from_email)
             VALUES (?, ?, ?, ?, ?, ?) RETURNING id',
            [$clientId, $idempotencyKey, 'Assunto', '<p>Olá</p>', 'Olá', 'loja@exemplo.com'],
        );
    }

    /** @param list<int|string|null> $params */
    private function insert(string $sql, array $params): int{
        $statement = $this->pdo->prepare($sql);
        self::assertNotFalse($statement);
        $statement->execute($params);

        $id = $statement->fetchColumn();
        self::assertNotFalse($id);

        return (int) $id;
    }

    /** @param list<int|string|null> $params */
    private function scalar(string $sql, array $params): string|int|null{
        $statement = $this->pdo->prepare($sql);
        self::assertNotFalse($statement);
        $statement->execute($params);

        $value = $statement->fetchColumn();
        self::assertNotFalse($value);

        return $value;
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
