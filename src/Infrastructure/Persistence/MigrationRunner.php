<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use PDO;
use RuntimeException;
use Throwable;

final class MigrationRunner
{
    private const LOCK_KEY = 'schema_migrations';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
    ) {
    }

    /** @return list<string> */
    public function run(): array{
        if (!is_dir($this->directory)) {
            throw new RuntimeException(sprintf('Migration directory not found: %s', $this->directory));
        }

        $this->pdo->query(sprintf("SELECT pg_advisory_lock(hashtext('%s'))", self::LOCK_KEY));

        try {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS schema_migrations (
                    name text PRIMARY KEY,
                    applied_at timestamptz NOT NULL DEFAULT now()
                )'
            );

            $applied = $this->applied();
            $done = [];

            foreach ($this->files() as $file) {
                $name = basename($file);

                if (isset($applied[$name])) {
                    continue;
                }

                $this->apply($file, $name);
                $done[] = $name;
            }

            return $done;
        } finally {
            $this->pdo->query(sprintf("SELECT pg_advisory_unlock(hashtext('%s'))", self::LOCK_KEY));
        }
    }

    private function apply(string $file, string $name): void{
        $sql = file_get_contents($file);

        if ($sql === false) {
            throw new RuntimeException(sprintf('Cannot read migration: %s', $file));
        }

        $this->pdo->beginTransaction();

        try {
            // shortcut: no non-transactional migrations (CREATE INDEX CONCURRENTLY), add a marker when one is needed
            $this->pdo->exec($sql);
            $this->pdo->prepare('INSERT INTO schema_migrations (name) VALUES (?)')->execute([$name]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /** @return array<string, true> */
    private function applied(): array{
        $rows = $this->pdo->query('SELECT name FROM schema_migrations');

        if ($rows === false) {
            throw new RuntimeException('Cannot read schema_migrations');
        }

        return array_fill_keys($rows->fetchAll(PDO::FETCH_COLUMN), true);
    }

    /** @return list<string> */
    private function files(): array{
        $files = glob($this->directory . '/*.sql') ?: [];
        sort($files, SORT_NATURAL);

        return $files;
    }
}
