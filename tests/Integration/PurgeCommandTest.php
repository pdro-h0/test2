<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Infrastructure\Persistence\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

final class PurgeCommandTest extends TestCase {
    private const RECIPIENT = 'purge-victim@exemplo.com';

    private PDO $pdo;
    private string $schema;

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

        $this->schema = 'purge_command_test_' . bin2hex(random_bytes(4));
        $this->pdo->exec('CREATE SCHEMA ' . $this->schema);
        $this->pdo->exec('SET search_path TO ' . $this->schema);

        (new MigrationRunner($this->pdo, __DIR__ . '/../../migrations'))->run();
    }

    protected function tearDown(): void {
        if (isset($this->schema)) {
            $this->pdo->exec('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
    }

    public function test_it_purges_and_logs_totals_without_content(): void {
        $clientId = $this->insertClient();
        $oldBatch = $this->insertBatch($clientId, '100 days');
        $recentBatch = $this->insertBatch($clientId, '40 days');

        $result = $this->runCommand($this->databaseEnv());

        self::assertSame(0, $result['code']);
        self::assertSame(0, $this->rowCount('SELECT count(*) FROM batches WHERE id = ?', [$oldBatch]));
        self::assertSame('', $this->scalar('SELECT html FROM batches WHERE id = ?', [$recentBatch]));
        self::assertSame('', $this->scalar('SELECT text FROM batches WHERE id = ?', [$recentBatch]));

        $logs = $this->logs($result['stdout']);
        self::assertSame(['purge_started', 'purge_completed'], array_column($logs, 'event'));
        self::assertSame(
            ['deleted_batches' => 1, 'hashed_batches' => 0, 'cleared_batches' => 1],
            array_intersect_key($logs[1] ?? [], array_flip(['deleted_batches', 'hashed_batches', 'cleared_batches']))
        );

        self::assertStringNotContainsString(self::RECIPIENT, $result['stdout']);
        self::assertStringNotContainsString('<p>', $result['stdout']);
    }

    public function test_it_succeeds_without_candidates(): void {
        $result = $this->runCommand($this->databaseEnv());

        self::assertSame(0, $result['code']);

        $logs = $this->logs($result['stdout']);
        self::assertSame(
            ['deleted_batches' => 0, 'hashed_batches' => 0, 'cleared_batches' => 0],
            array_intersect_key($logs[1] ?? [], array_flip(['deleted_batches', 'hashed_batches', 'cleared_batches']))
        );
    }

    public function test_it_fails_safely_with_invalid_configuration(): void {
        $result = $this->runCommand([
            'POSTGRES_HOST' => '127.0.0.1',
            'POSTGRES_PORT' => '1',
        ] + $this->databaseEnv());

        self::assertSame(1, $result['code']);

        $logs = $this->logs($result['stdout']);
        $failure = $logs[1] ?? [];
        self::assertSame('purge_failed', $failure['event'] ?? null);
        self::assertSame('error', $failure['level'] ?? null);
        self::assertSame('RuntimeException: PDOException', $failure['error'] ?? null);

        self::assertStringNotContainsString('SQLSTATE', $result['stdout']);
        self::assertStringNotContainsString(self::env('POSTGRES_PASSWORD'), $result['stdout']);
    }

    /** @return array<string, string> */
    private function databaseEnv(): array {
        return [
            'POSTGRES_HOST' => self::env('POSTGRES_HOST'),
            'POSTGRES_PORT' => self::env('POSTGRES_PORT'),
            'POSTGRES_DB' => self::env('POSTGRES_DB'),
            'POSTGRES_USER' => self::env('POSTGRES_USER'),
            'POSTGRES_PASSWORD' => self::env('POSTGRES_PASSWORD'),
            'PGOPTIONS' => '-c search_path=' . $this->schema,
        ];
    }

    /**
     * @param array<string, string> $env
     * @return array{code: int, stdout: string}
     */
    private function runCommand(array $env): array {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../../bin/purge.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            __DIR__ . '/../..',
            ['XDEBUG_MODE' => 'off'] + $env + (getenv() ?: []),
        );

        self::assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertIsString($stdout);
        self::assertIsString($stderr);

        return ['code' => proc_close($process), 'stdout' => $stdout];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function logs(string $stdout): array {
        $logs = [];

        foreach (explode("\n", trim($stdout)) as $line) {
            $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            $logs[] = $decoded;
        }

        return $logs;
    }

    private function insertClient(): int {
        return $this->insert(
            'INSERT INTO clients (name, api_key_hash, from_email, rate_limit_per_sec)
             VALUES (?, ?, ?, ?) RETURNING id',
            ['Loja', hash('sha256', 'chave-secreta'), 'loja@exemplo.com', 10],
        );
    }

    private function insertBatch(int $clientId, string $age): int {
        $batchId = $this->insert(
            "INSERT INTO batches (client_id, subject, html, text, from_email, created_at)
             VALUES (?, ?, ?, ?, ?, now() - ?::interval) RETURNING id",
            [$clientId, 'Assunto', '<p>Olá</p>', 'Olá', 'loja@exemplo.com', $age],
        );

        $this->pdo
            ->prepare("INSERT INTO messages (batch_id, client_id, recipient, status) VALUES (?, ?, ?, 'sent')")
            ->execute([$batchId, $clientId, self::RECIPIENT]);

        return $batchId;
    }

    /** @param list<int|string> $params */
    private function insert(string $sql, array $params): int {
        $statement = $this->pdo->prepare($sql);
        self::assertNotFalse($statement);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /** @param list<int|string> $params */
    private function rowCount(string $sql, array $params): int {
        return (int) $this->scalar($sql, $params);
    }

    /** @param list<int|string> $params */
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
