<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\BatchPayloadHash;
use App\Infrastructure\Persistence\MigrationRunner;
use App\Infrastructure\Persistence\PdoRetentionRepository;
use DateTimeImmutable;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class PdoRetentionRepositoryTest extends TestCase {
    private PDO $pdo;
    private string $schema;
    private PdoRetentionRepository $repository;
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

        $this->schema = 'retention_test_' . bin2hex(random_bytes(4));
        $this->pdo->exec('CREATE SCHEMA ' . $this->schema);
        $this->pdo->exec('SET search_path TO ' . $this->schema);

        (new MigrationRunner($this->pdo, __DIR__ . '/../../migrations'))->run();

        $this->repository = new PdoRetentionRepository($this->pdo);
        $this->clientId = $this->insert(
            'INSERT INTO clients (name, api_key_hash, from_email, rate_limit_per_sec)
             VALUES (?, ?, ?, ?) RETURNING id',
            ['Loja', hash('sha256', 'chave-secreta'), 'loja@exemplo.com', 10],
        );
    }

    protected function tearDown(): void {
        if (isset($this->schema)) {
            $this->pdo->exec('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
    }

    public function test_it_fills_the_hash_of_an_old_batch_in_message_id_order(): void {
        $recipients = ['a@exemplo.com', 'b@exemplo.com'];
        $batchId = $this->batch('ABC-1', $recipients);

        self::assertSame(1, $this->repository->fillMissingPayloadHashes(100));

        self::assertSame(
            BatchPayloadHash::of('Assunto', '<p>Olá</p>', 'Olá', 'loja@exemplo.com', $recipients),
            $this->scalar('SELECT payload_hash FROM batches WHERE id = ?', [$batchId]),
        );
        self::assertSame('<p>Olá</p>', $this->scalar('SELECT html FROM batches WHERE id = ?', [$batchId]));
        self::assertSame('Olá', $this->scalar('SELECT text FROM batches WHERE id = ?', [$batchId]));
    }

    public function test_it_skips_a_purged_batch_without_a_hash(): void {
        $batchId = $this->batch('ABC-1', ['a@exemplo.com'], purged: true);

        self::assertSame(0, $this->repository->fillMissingPayloadHashes(100));
        self::assertNull($this->scalar('SELECT payload_hash FROM batches WHERE id = ?', [$batchId]));
    }

    public function test_it_does_not_change_an_existing_hash(): void {
        $batchId = $this->batch('ABC-1', ['a@exemplo.com']);
        $this->repository->fillMissingPayloadHashes(100);
        $hash = $this->scalar('SELECT payload_hash FROM batches WHERE id = ?', [$batchId]);

        self::assertSame(0, $this->repository->fillMissingPayloadHashes(100));
        self::assertSame($hash, $this->scalar('SELECT payload_hash FROM batches WHERE id = ?', [$batchId]));
    }

    public function test_it_respects_the_group_limit(): void {
        $first = $this->batch('ABC-1', ['a@exemplo.com']);
        $second = $this->batch('ABC-2', ['b@exemplo.com']);

        self::assertSame(1, $this->repository->fillMissingPayloadHashes(1));
        self::assertNotNull($this->scalar('SELECT payload_hash FROM batches WHERE id = ?', [$first]));
        self::assertNull($this->scalar('SELECT payload_hash FROM batches WHERE id = ?', [$second]));

        self::assertSame(1, $this->repository->fillMissingPayloadHashes(1));
        self::assertNotNull($this->scalar('SELECT payload_hash FROM batches WHERE id = ?', [$second]));
    }

    public function test_it_skips_a_batch_locked_by_another_connection(): void {
        $first = $this->batch('ABC-1', ['a@exemplo.com']);
        $second = $this->batch('ABC-2', ['b@exemplo.com']);

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
        $other->beginTransaction();
        $other->prepare('SELECT id FROM batches WHERE id = ? FOR UPDATE')->execute([$first]);

        self::assertSame(1, $this->repository->fillMissingPayloadHashes(100));
        self::assertNull($this->scalar('SELECT payload_hash FROM batches WHERE id = ?', [$first]));
        self::assertNotNull($this->scalar('SELECT payload_hash FROM batches WHERE id = ?', [$second]));

        $other->commit();

        self::assertSame(1, $this->repository->fillMissingPayloadHashes(100));
        self::assertNotNull($this->scalar('SELECT payload_hash FROM batches WHERE id = ?', [$first]));
    }

    public function test_it_clears_a_terminal_recent_batch(): void {
        $batchId = $this->batch(
            null,
            ['a@exemplo.com', 'b@exemplo.com'],
            statuses: ['sent', 'failed'],
            createdAt: '2026-10-01T00:00:00+00:00',
        );

        self::assertSame(1, $this->repository->clearContent($this->limit(), $this->purgeDate(), 100));

        $this->assertContentCleared($batchId);
        self::assertSame(2, $this->messageCount($batchId));
    }

    public function test_it_preserves_a_recent_pending_batch(): void {
        $batchId = $this->batch(
            null,
            ['a@exemplo.com'],
            createdAt: '2026-10-01T00:00:00+00:00',
        );

        self::assertSame(0, $this->repository->clearContent($this->limit(), $this->purgeDate(), 100));

        self::assertNull($this->purgedAt($batchId));
        self::assertSame('<p>Olá</p>', $this->scalar('SELECT html FROM batches WHERE id = ?', [$batchId]));
    }

    public function test_a_recent_batch_without_messages_is_not_terminal(): void {
        $batchId = $this->batch(null, [], createdAt: '2026-10-01T00:00:00+00:00');

        self::assertSame(0, $this->repository->clearContent($this->limit(), $this->purgeDate(), 100));

        self::assertNull($this->purgedAt($batchId));
    }

    public function test_it_clears_a_pending_batch_at_the_creation_limit(): void {
        $batchId = $this->batch(
            null,
            ['a@exemplo.com', 'b@exemplo.com'],
            createdAt: '2026-09-23T00:00:00+00:00',
        );

        self::assertSame(1, $this->repository->clearContent($this->limit(), $this->purgeDate(), 100));

        $this->assertContentCleared($batchId);
        self::assertSame(2, $this->messageCount($batchId));
    }

    public function test_it_clears_a_pending_batch_created_before_the_limit(): void {
        $batchId = $this->batch(null, ['a@exemplo.com'], createdAt: '2026-09-22T23:59:59+00:00');

        self::assertSame(1, $this->repository->clearContent($this->limit(), $this->purgeDate(), 100));

        $this->assertContentCleared($batchId);
    }

    public function test_it_clears_mixed_terminal_statuses_but_not_queued_or_sending(): void {
        $terminal = $this->batch(null, ['a@exemplo.com', 'b@exemplo.com'], statuses: ['sent', 'failed']);
        $queued = $this->batch(null, ['c@exemplo.com', 'd@exemplo.com'], statuses: ['sent', 'queued']);
        $sending = $this->batch(null, ['e@exemplo.com', 'f@exemplo.com'], statuses: ['sending', 'failed']);

        self::assertSame(1, $this->repository->clearContent($this->limit(), $this->purgeDate(), 100));

        $this->assertContentCleared($terminal);
        self::assertNull($this->purgedAt($queued));
        self::assertNull($this->purgedAt($sending));
    }

    public function test_a_second_run_does_not_change_the_purge_timestamp(): void {
        $batchId = $this->batch(null, ['a@exemplo.com'], statuses: ['sent']);
        $firstPurge = new DateTimeImmutable('2026-10-23T01:00:00+00:00');
        $secondPurge = new DateTimeImmutable('2026-10-24T01:00:00+00:00');

        self::assertSame(1, $this->repository->clearContent($this->limit(), $firstPurge, 100));

        $this->assertContentCleared($batchId, $firstPurge);
        self::assertSame(0, $this->repository->clearContent($this->limit(), $secondPurge, 100));
        self::assertSame($firstPurge->format(DATE_ATOM), $this->purgedAt($batchId));
    }

    public function test_clear_content_respects_the_group_limit(): void {
        $first = $this->batch(null, ['a@exemplo.com'], statuses: ['sent']);
        $second = $this->batch(null, ['b@exemplo.com'], statuses: ['sent']);

        self::assertSame(1, $this->repository->clearContent($this->limit(), $this->purgeDate(), 1));

        $this->assertContentCleared($first);
        self::assertNull($this->purgedAt($second));

        self::assertSame(1, $this->repository->clearContent($this->limit(), $this->purgeDate(), 1));
        $this->assertContentCleared($second);
    }

    public function test_it_skips_an_idempotent_batch_without_a_payload_hash(): void {
        $batchId = $this->batch('ABC-1', ['a@exemplo.com'], statuses: ['sent']);

        self::assertSame(0, $this->repository->clearContent($this->limit(), $this->purgeDate(), 100));

        self::assertNull($this->purgedAt($batchId));
        self::assertSame('<p>Olá</p>', $this->scalar('SELECT html FROM batches WHERE id = ?', [$batchId]));
    }

    public function test_it_clears_an_idempotent_batch_with_a_payload_hash(): void {
        $batchId = $this->batch(
            'ABC-2',
            ['a@exemplo.com'],
            statuses: ['sent'],
            payloadHash: str_repeat('b', 64),
        );

        self::assertSame(1, $this->repository->clearContent($this->limit(), $this->purgeDate(), 100));

        $this->assertContentCleared($batchId);
    }

    public function test_it_rolls_back_the_whole_group_on_failure(): void {
        $first = $this->batch(null, ['a@exemplo.com'], statuses: ['sent']);
        $second = $this->batch(null, ['b@exemplo.com'], statuses: ['sent']);

        $this->pdo->exec(sprintf(
            "CREATE FUNCTION fail_batch_update() RETURNS trigger AS \$\$
             BEGIN
                 IF NEW.id = %d THEN RAISE EXCEPTION 'boom'; END IF;
                 RETURN NEW;
             END;
             \$\$ LANGUAGE plpgsql",
            $second,
        ));
        $this->pdo->exec(
            'CREATE TRIGGER fail_batch_update BEFORE UPDATE ON batches
             FOR EACH ROW EXECUTE FUNCTION fail_batch_update()'
        );

        try {
            $this->repository->clearContent($this->limit(), $this->purgeDate(), 100);
            self::fail('Expected the group to fail.');
        } catch (PDOException) {
        }

        self::assertFalse($this->pdo->inTransaction());
        self::assertNull($this->purgedAt($first));
        self::assertNull($this->purgedAt($second));
        self::assertSame('<p>Olá</p>', $this->scalar('SELECT html FROM batches WHERE id = ?', [$first]));
    }

    public function test_clear_content_skips_a_batch_locked_by_another_connection(): void {
        $first = $this->batch(null, ['a@exemplo.com'], statuses: ['sent']);
        $second = $this->batch(null, ['b@exemplo.com'], statuses: ['sent']);

        $other = $this->otherConnection();
        $other->beginTransaction();
        $other->prepare('SELECT id FROM batches WHERE id = ? FOR UPDATE')->execute([$first]);

        self::assertSame(1, $this->repository->clearContent($this->limit(), $this->purgeDate(), 100));

        self::assertNull($this->purgedAt($first));
        $this->assertContentCleared($second);

        $other->commit();

        self::assertSame(1, $this->repository->clearContent($this->limit(), $this->purgeDate(), 100));
        $this->assertContentCleared($first);
    }

    public function test_it_deletes_a_terminal_batch_at_the_age_limit(): void {
        $batchId = $this->batch(
            null,
            ['a@exemplo.com', 'b@exemplo.com'],
            statuses: ['sent', 'failed'],
            createdAt: '2026-07-25T00:00:00+00:00',
        );
        $this->outboxFor($batchId);

        self::assertSame(1, $this->repository->deleteTerminalBatchesBefore($this->deleteLimit(), 100));

        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM batches WHERE id = ?', [$batchId]));
        self::assertSame(0, $this->messageCount($batchId));
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM outbox', []));
    }

    public function test_it_preserves_a_batch_created_after_the_age_limit(): void {
        $batchId = $this->batch(
            null,
            ['a@exemplo.com'],
            statuses: ['sent'],
            createdAt: '2026-07-25T00:00:01+00:00',
        );

        self::assertSame(0, $this->repository->deleteTerminalBatchesBefore($this->deleteLimit(), 100));

        self::assertSame(1, $this->messageCount($batchId));
    }

    public function test_it_preserves_an_old_batch_with_a_pending_message(): void {
        $queued = $this->batch(
            null,
            ['a@exemplo.com', 'b@exemplo.com'],
            statuses: ['sent', 'queued'],
            createdAt: '2026-07-24T00:00:00+00:00',
        );
        $sending = $this->batch(
            null,
            ['c@exemplo.com'],
            statuses: ['sending'],
            createdAt: '2026-07-24T00:00:00+00:00',
        );

        self::assertSame(0, $this->repository->deleteTerminalBatchesBefore($this->deleteLimit(), 100));

        self::assertSame(2, $this->messageCount($queued));
        self::assertSame(1, $this->messageCount($sending));
    }

    public function test_it_preserves_an_old_batch_without_messages(): void {
        $batchId = $this->batch(null, [], createdAt: '2026-07-24T00:00:00+00:00');

        self::assertSame(0, $this->repository->deleteTerminalBatchesBefore($this->deleteLimit(), 100));

        self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM batches WHERE id = ?', [$batchId]));
    }

    public function test_it_preserves_clients_other_batches_and_rate_limits(): void {
        $old = $this->batch(null, ['a@exemplo.com'], statuses: ['sent'], createdAt: '2026-07-24T00:00:00+00:00');
        $recent = $this->batch(null, ['b@exemplo.com'], statuses: ['sent']);
        $this->pdo
            ->prepare('INSERT INTO rate_limits (client_id, window_start, hits) VALUES (?, now(), 3)')
            ->execute([$this->clientId]);

        self::assertSame(1, $this->repository->deleteTerminalBatchesBefore($this->deleteLimit(), 100));

        self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM clients WHERE id = ?', [$this->clientId]));
        self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM rate_limits WHERE client_id = ?', [$this->clientId]));
        self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM batches WHERE id = ?', [$recent]));
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM batches WHERE id = ?', [$old]));
    }

    public function test_a_second_delete_run_removes_nothing(): void {
        $this->batch(null, ['a@exemplo.com'], statuses: ['sent'], createdAt: '2026-07-24T00:00:00+00:00');

        self::assertSame(1, $this->repository->deleteTerminalBatchesBefore($this->deleteLimit(), 100));
        self::assertSame(0, $this->repository->deleteTerminalBatchesBefore($this->deleteLimit(), 100));
    }

    public function test_delete_respects_the_group_limit(): void {
        $first = $this->batch(null, ['a@exemplo.com'], statuses: ['sent'], createdAt: '2026-07-24T00:00:00+00:00');
        $second = $this->batch(null, ['b@exemplo.com'], statuses: ['sent'], createdAt: '2026-07-24T00:00:00+00:00');

        self::assertSame(1, $this->repository->deleteTerminalBatchesBefore($this->deleteLimit(), 1));
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM batches WHERE id = ?', [$first]));
        self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM batches WHERE id = ?', [$second]));

        self::assertSame(1, $this->repository->deleteTerminalBatchesBefore($this->deleteLimit(), 1));
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM batches WHERE id = ?', [$second]));
    }

    public function test_delete_rolls_back_the_whole_group_on_failure(): void {
        $first = $this->batch(null, ['a@exemplo.com'], statuses: ['sent'], createdAt: '2026-07-24T00:00:00+00:00');
        $second = $this->batch(null, ['b@exemplo.com'], statuses: ['sent'], createdAt: '2026-07-24T00:00:00+00:00');

        $this->pdo->exec(sprintf(
            "CREATE FUNCTION fail_batch_delete() RETURNS trigger AS \$\$
             BEGIN
                 IF OLD.id = %d THEN RAISE EXCEPTION 'boom'; END IF;
                 RETURN OLD;
             END;
             \$\$ LANGUAGE plpgsql",
            $second,
        ));
        $this->pdo->exec(
            'CREATE TRIGGER fail_batch_delete BEFORE DELETE ON batches
             FOR EACH ROW EXECUTE FUNCTION fail_batch_delete()'
        );

        try {
            $this->repository->deleteTerminalBatchesBefore($this->deleteLimit(), 100);
            self::fail('Expected the group to fail.');
        } catch (PDOException) {
        }

        self::assertFalse($this->pdo->inTransaction());
        self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM batches WHERE id = ?', [$first]));
        self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM batches WHERE id = ?', [$second]));
        self::assertSame(1, $this->messageCount($first));
    }

    public function test_delete_skips_a_batch_locked_by_another_connection(): void {
        $first = $this->batch(null, ['a@exemplo.com'], statuses: ['sent'], createdAt: '2026-07-24T00:00:00+00:00');
        $second = $this->batch(null, ['b@exemplo.com'], statuses: ['sent'], createdAt: '2026-07-24T00:00:00+00:00');

        $other = $this->otherConnection();
        $other->beginTransaction();
        $other->prepare('SELECT id FROM batches WHERE id = ? FOR UPDATE')->execute([$first]);

        self::assertSame(1, $this->repository->deleteTerminalBatchesBefore($this->deleteLimit(), 100));

        self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM batches WHERE id = ?', [$first]));
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM batches WHERE id = ?', [$second]));

        $other->commit();

        self::assertSame(1, $this->repository->deleteTerminalBatchesBefore($this->deleteLimit(), 100));
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM batches WHERE id = ?', [$first]));
    }

    public function test_the_idempotency_key_can_be_used_again_after_deletion(): void {
        $old = $this->batch('ABC-9', ['a@exemplo.com'], statuses: ['sent'], createdAt: '2026-07-24T00:00:00+00:00');

        self::assertSame(1, $this->repository->deleteTerminalBatchesBefore($this->deleteLimit(), 100));
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM batches WHERE id = ?', [$old]));

        $new = $this->batch('ABC-9', ['b@exemplo.com']);

        self::assertNotSame($old, $new);
        self::assertSame(1, $this->messageCount($new));
    }

    /**
     * @param list<string> $recipients
     * @param list<string> $statuses
     */
    private function batch(
        ?string $idempotencyKey,
        array $recipients,
        bool $purged = false,
        ?string $createdAt = null,
        array $statuses = [],
        ?string $payloadHash = null,
    ): int {
        $batchId = $this->insert(
            'INSERT INTO batches (client_id, idempotency_key, subject, html, text, from_email, created_at, payload_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
            [
                $this->clientId,
                $idempotencyKey,
                'Assunto',
                '<p>Olá</p>',
                'Olá',
                'loja@exemplo.com',
                $createdAt ?? (new DateTimeImmutable())->format(DATE_ATOM),
                $payloadHash,
            ],
        );

        $insertMessage = $this->pdo->prepare(
            'INSERT INTO messages (batch_id, client_id, recipient, status) VALUES (?, ?, ?, ?)'
        );

        foreach ($recipients as $index => $recipient) {
            $insertMessage->execute([$batchId, $this->clientId, $recipient, $statuses[$index] ?? 'queued']);
        }

        if ($purged) {
            $this->pdo
                ->prepare("UPDATE batches SET html = '', text = '', html_purged_at = now() WHERE id = ?")
                ->execute([$batchId]);
        }

        return $batchId;
    }

    private function limit(): DateTimeImmutable {
        return new DateTimeImmutable('2026-09-23T00:00:00+00:00');
    }

    private function purgeDate(): DateTimeImmutable {
        return new DateTimeImmutable('2026-10-23T00:00:00+00:00');
    }

    private function assertContentCleared(int $batchId, ?DateTimeImmutable $purgedAt = null): void {
        self::assertSame('', $this->scalar('SELECT html FROM batches WHERE id = ?', [$batchId]));
        self::assertSame('', $this->scalar('SELECT text FROM batches WHERE id = ?', [$batchId]));
        self::assertSame(($purgedAt ?? $this->purgeDate())->format(DATE_ATOM), $this->purgedAt($batchId));
    }

    private function purgedAt(int $batchId): ?string {
        $value = $this->scalar('SELECT html_purged_at FROM batches WHERE id = ?', [$batchId]);

        return $value === null ? null : (new DateTimeImmutable((string) $value))->format(DATE_ATOM);
    }

    private function messageCount(int $batchId): int {
        return (int) $this->scalar('SELECT count(*) FROM messages WHERE batch_id = ?', [$batchId]);
    }

    private function outboxFor(int $batchId): void {
        $this->pdo
            ->prepare('INSERT INTO outbox (message_id) SELECT id FROM messages WHERE batch_id = ?')
            ->execute([$batchId]);
    }

    private function deleteLimit(): DateTimeImmutable {
        return new DateTimeImmutable('2026-07-25T00:00:00+00:00');
    }

    private function otherConnection(): PDO {
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

        return $other;
    }

    /** @param list<int|string|null> $params */
    private function insert(string $sql, array $params): int {
        $statement = $this->pdo->prepare($sql);
        self::assertNotFalse($statement);
        $statement->execute($params);

        $id = $statement->fetchColumn();
        self::assertNotFalse($id);

        return (int) $id;
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
