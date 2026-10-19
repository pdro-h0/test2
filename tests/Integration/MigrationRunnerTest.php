<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Infrastructure\Persistence\MigrationRunner;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class MigrationRunnerTest extends TestCase{
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

        $this->schema = 'migrate_test_' . bin2hex(random_bytes(4));
        $this->pdo->exec('CREATE SCHEMA ' . $this->schema);
        $this->pdo->exec('SET search_path TO ' . $this->schema);
    }

    protected function tearDown(): void{
        $this->pdo->exec('DROP SCHEMA ' . $this->schema . ' CASCADE');
    }

    public function test_it_applies_each_migration_once(): void{
        $runner = new MigrationRunner($this->pdo, __DIR__ . '/Fixtures/migrations');

        self::assertSame(['001_create_widgets.sql', '002_add_label.sql'], $runner->run());
        self::assertSame([], $runner->run());
        self::assertSame(2, $this->scalar('SELECT count(*) FROM schema_migrations'));
        self::assertSame(1, $this->scalar(
            "SELECT count(*) FROM information_schema.columns
             WHERE table_schema = current_schema() AND table_name = 'widgets' AND column_name = 'label'",
        ));
    }

    public function test_it_rolls_back_a_failed_migration(): void{
        $runner = new MigrationRunner($this->pdo, __DIR__ . '/Fixtures/broken_migrations');

        try {
            $runner->run();
            self::fail('A broken migration must not pass.');
        } catch (PDOException) {
        }

        self::assertSame(0, $this->scalar('SELECT count(*) FROM schema_migrations'));
        self::assertNull($this->scalar("SELECT to_regclass('broken')"));
    }

    private function scalar(string $sql): int|string|bool|null{
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);

        return $statement->fetchColumn();
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
