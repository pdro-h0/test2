<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Client;
use App\Infrastructure\Persistence\MigrationRunner;
use App\Infrastructure\Persistence\PdoClientRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoClientRepositoryTest extends TestCase
{
    private PDO $pdo;
    private string $schema;
    private PdoClientRepository $repository;

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

        $this->schema = 'client_test_' . bin2hex(random_bytes(4));
        $this->pdo->exec('CREATE SCHEMA ' . $this->schema);
        $this->pdo->exec('SET search_path TO ' . $this->schema);

        (new MigrationRunner($this->pdo, __DIR__ . '/../../migrations'))->run();

        $this->repository = new PdoClientRepository($this->pdo);
    }

    protected function tearDown(): void{
        if (isset($this->schema)) {
            $this->pdo->exec('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
    }

    public function test_it_finds_client_by_api_key_hash(): void{
        $apiKey = 'chave-secreta';
        $id = $this->createClient($apiKey);

        $client = $this->repository->findByApiKeyHash(hash('sha256', $apiKey));

        self::assertNotNull($client);
        self::assertSame($id, $client->id);
        self::assertSame('Loja', $client->name);
        self::assertSame('loja@exemplo.com', $client->fromEmail);
        self::assertSame('Loja', $client->fromName);
        self::assertSame(10, $client->rateLimitPerSec);
        self::assertTrue($client->isActive);
    }

    public function test_it_does_not_store_the_plain_key(): void{
        $apiKey = 'chave-secreta';
        $id = $this->createClient($apiKey);

        self::assertSame(0, $this->scalar('SELECT count(*) FROM clients WHERE api_key_hash = ?', [$apiKey]));
        self::assertSame(
            hash('sha256', $apiKey),
            $this->scalar('SELECT api_key_hash FROM clients WHERE id = ?', [$id]),
        );
    }

    public function test_it_finds_nothing_for_unknown_hash(): void{
        $this->createClient('chave-secreta');

        self::assertNull($this->repository->findByApiKeyHash(hash('sha256', 'outra-chave')));
    }

    public function test_it_returns_inactive_client_with_flag(): void{
        $apiKey = 'chave-desativada';
        $this->createClient($apiKey, isActive: false);

        $client = $this->repository->findByApiKeyHash(hash('sha256', $apiKey));

        self::assertNotNull($client);
        self::assertFalse($client->isActive);
    }

    private function createClient(string $apiKey, bool $isActive = true): int{
        return $this->repository->create(new Client(
            id: null,
            name: 'Loja',
            apiKeyHash: hash('sha256', $apiKey),
            fromEmail: 'loja@exemplo.com',
            fromName: 'Loja',
            rateLimitPerSec: 10,
            isActive: $isActive,
        ));
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
