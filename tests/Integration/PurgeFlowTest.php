<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\CreateBatchInput;
use App\Application\CreateBatchUseCase;
use App\Application\PurgeRetentionUseCase;
use App\Application\SendMessageUseCase;
use App\Domain\Client;
use App\Domain\Exception\ConflictException;
use App\Infrastructure\Persistence\MigrationRunner;
use App\Infrastructure\Persistence\PdoBatchRepository;
use App\Infrastructure\Persistence\PdoClientRepository;
use App\Infrastructure\Persistence\PdoMessageRepository;
use App\Infrastructure\Persistence\PdoRateLimiter;
use App\Infrastructure\Persistence\PdoRetentionRepository;
use App\Tests\Fake\RecordingMailer;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

final class PurgeFlowTest extends TestCase {
    private const API_KEY = 'chave-secreta';
    private const GROUP_OVERFLOW = 101;

    private PDO $pdo;
    private string $schema;
    private Client $client;

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

        $this->schema = 'purge_flow_test_' . bin2hex(random_bytes(4));
        $this->pdo->exec('CREATE SCHEMA ' . $this->schema);
        $this->pdo->exec('SET search_path TO ' . $this->schema);

        (new MigrationRunner($this->pdo, __DIR__ . '/../../migrations'))->run();

        $clients = new PdoClientRepository($this->pdo);
        $clients->create(new Client(
            id: null,
            name: 'Loja',
            apiKeyHash: hash('sha256', self::API_KEY),
            fromEmail: 'loja@exemplo.com',
            fromName: 'Loja',
            rateLimitPerSec: 1000,
            isActive: true,
        ));
        $this->client = $clients->findByApiKeyHash(hash('sha256', self::API_KEY))
            ?? self::fail('Client was not persisted.');
    }

    protected function tearDown(): void {
        if (isset($this->schema)) {
            $this->pdo->exec('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
    }

    public function test_the_key_repeats_after_purge_and_a_different_payload_conflicts(): void {
        $input = $this->input(idempotencyKey: 'ABC-1');
        $first = $this->createBatch($input);
        $this->markTerminal($first['batch_id']);

        $totals = $this->purge();

        self::assertSame(0, $totals['deleted_batches']);
        self::assertSame(0, $totals['hashed_batches']);
        self::assertSame(1, $totals['cleared_batches']);
        self::assertSame('', $this->scalar('SELECT html FROM batches WHERE id = ?', [$first['batch_id']]));
        self::assertSame('', $this->scalar('SELECT text FROM batches WHERE id = ?', [$first['batch_id']]));

        $second = $this->createBatch($input);

        self::assertFalse($second['is_new']);
        self::assertSame($first['batch_id'], $second['batch_id']);
        self::assertSame($first['message_ids'], $second['message_ids']);

        try {
            $this->createBatch($this->input(idempotencyKey: 'ABC-1', subject: 'Outro assunto'));
            self::fail('Expected a conflict for a different payload after purge.');
        } catch (ConflictException) {
        }

        self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM batches', []));
    }

    public function test_an_old_pending_batch_keeps_metadata_and_fails_without_smtp(): void {
        $saved = $this->createBatch($this->input(idempotencyKey: 'ABC-2'));
        $this->pdo
            ->prepare("UPDATE batches SET created_at = now() - interval '31 days' WHERE id = ?")
            ->execute([$saved['batch_id']]);

        $totals = $this->purge();

        self::assertSame(1, $totals['cleared_batches']);
        self::assertSame('', $this->scalar('SELECT html FROM batches WHERE id = ?', [$saved['batch_id']]));
        self::assertSame('Assunto', $this->scalar('SELECT subject FROM batches WHERE id = ?', [$saved['batch_id']]));
        self::assertNotNull($this->scalar('SELECT payload_hash FROM batches WHERE id = ?', [$saved['batch_id']]));
        self::assertSame(2, (int) $this->scalar('SELECT count(*) FROM messages WHERE batch_id = ?', [$saved['batch_id']]));

        $mailer = new RecordingMailer();
        $status = (new SendMessageUseCase(new PdoMessageRepository($this->pdo), $mailer))->run($saved['message_ids'][0]);

        self::assertSame('failed', $status->value);
        self::assertSame([], $mailer->recipients);
        self::assertSame('message content expired', $this->scalar('SELECT last_error FROM messages WHERE id = ?', [$saved['message_ids'][0]]));
    }

    public function test_it_deletes_more_than_one_group_and_a_second_run_does_nothing(): void {
        $this->pdo
            ->prepare(
                "INSERT INTO batches (client_id, subject, html, text, from_email, created_at)
                 SELECT ?, 'Assunto', '<p>Olá</p>', 'Olá', 'loja@exemplo.com', now() - interval '100 days'
                 FROM generate_series(1, ?)"
            )
            ->execute([$this->client->id, self::GROUP_OVERFLOW]);
        $this->pdo
            ->prepare("INSERT INTO messages (batch_id, client_id, recipient, status)
                      SELECT id, ?, 'bulk@exemplo.com', 'sent' FROM batches")
            ->execute([$this->client->id]);
        $this->pdo->exec('INSERT INTO outbox (message_id) SELECT id FROM messages');

        $first = $this->purge();

        self::assertSame(self::GROUP_OVERFLOW, $first['deleted_batches']);
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM batches', []));
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM messages', []));
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM outbox', []));

        self::assertSame(
            ['deleted_batches' => 0, 'hashed_batches' => 0, 'cleared_batches' => 0],
            $this->purge()
        );
    }

    private function input(?string $idempotencyKey, string $subject = 'Assunto'): CreateBatchInput {
        return new CreateBatchInput(
            subject: $subject,
            html: '<p>Olá</p>',
            text: null,
            from: null,
            recipients: ['a@exemplo.com', 'b@exemplo.com'],
            idempotencyKey: $idempotencyKey,
        );
    }

    /** @return array{batch_id: int, message_ids: list<int>, is_new: bool} */
    private function createBatch(CreateBatchInput $input): array {
        return (new CreateBatchUseCase(
            new PdoBatchRepository($this->pdo),
            new PdoRateLimiter($this->pdo),
        ))->run($this->client, $input);
    }

    /** @return array{deleted_batches: int, hashed_batches: int, cleared_batches: int} */
    private function purge(): array {
        return (new PurgeRetentionUseCase(new PdoRetentionRepository($this->pdo)))->run(
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
    }

    private function markTerminal(int $batchId): void {
        $this->pdo
            ->prepare("UPDATE messages SET status = 'sent', sent_at = now() WHERE batch_id = ?")
            ->execute([$batchId]);
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
