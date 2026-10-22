<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Client;
use App\Infrastructure\Persistence\MigrationRunner;
use App\Infrastructure\Persistence\PdoClientRepository;
use App\Infrastructure\Persistence\PdoRateLimiter;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoRateLimiterTest extends TestCase {
    private PDO $pdo;
    private string $schema;
    private PdoRateLimiter $limiter;
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

        $this->schema = 'rate_limit_test_' . bin2hex(random_bytes(4));
        $this->pdo->exec('CREATE SCHEMA ' . $this->schema);
        $this->pdo->exec('SET search_path TO ' . $this->schema);

        (new MigrationRunner($this->pdo, __DIR__ . '/../../migrations'))->run();

        $this->clientId = (new PdoClientRepository($this->pdo))->create(new Client(
            id: null,
            name: 'Loja',
            apiKeyHash: hash('sha256', 'chave-secreta'),
            fromEmail: 'loja@exemplo.com',
            fromName: 'Loja',
            rateLimitPerSec: 3,
            isActive: true,
        ));
        $this->limiter = new PdoRateLimiter($this->pdo);
    }

    protected function tearDown(): void {
        if (isset($this->schema)) {
            $this->pdo->exec('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
    }

    public function test_it_allows_requests_up_to_the_limit_and_rejects_the_next(): void {
        // One transaction pins now(): the fixed window cannot roll in the middle of the test.
        $this->pdo->beginTransaction();

        self::assertTrue($this->limiter->allow($this->clientId, 3));
        self::assertTrue($this->limiter->allow($this->clientId, 3));
        self::assertTrue($this->limiter->allow($this->clientId, 3));
        self::assertFalse($this->limiter->allow($this->clientId, 3));

        $this->pdo->rollBack();
    }

    public function test_it_counts_from_one_in_a_new_window(): void {
        $this->pdo->beginTransaction();

        self::assertTrue($this->limiter->allow($this->clientId, 1));
        self::assertFalse($this->limiter->allow($this->clientId, 1));

        $this->pdo->exec("UPDATE rate_limits SET window_start = window_start - interval '2 seconds'");

        self::assertTrue($this->limiter->allow($this->clientId, 1));
        self::assertSame('1', $this->scalar('SELECT hits FROM rate_limits WHERE client_id = ?', [$this->clientId]));

        $this->pdo->rollBack();
    }

    /** @param list<int|string|null> $params */
    private function scalar(string $sql, array $params = []): string {
        $statement = $this->pdo->prepare($sql);
        self::assertNotFalse($statement);
        $statement->execute($params);

        return (string) $statement->fetchColumn();
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
