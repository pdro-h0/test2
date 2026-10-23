<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\CreateBatchUseCase;
use App\Domain\Client;
use App\Http\Auth;
use App\Http\Controller\CreateBatchController;
use App\Http\ErrorHandler;
use App\Http\JsonBody;
use App\Http\Request;
use App\Infrastructure\Log\JsonLogger;
use App\Infrastructure\Persistence\MigrationRunner;
use App\Infrastructure\Persistence\PdoBatchRepository;
use App\Infrastructure\Persistence\PdoClientRepository;
use App\Infrastructure\Persistence\PdoRateLimiter;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

final class CreateBatchControllerTest extends TestCase
{
    private const API_KEY = 'chave-secreta';

    private PDO $pdo;
    private string $schema;
    private CreateBatchController $controller;
    private ErrorHandler $errors;
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

        $this->schema = 'http_test_' . bin2hex(random_bytes(4));
        $this->pdo->exec('CREATE SCHEMA ' . $this->schema);
        $this->pdo->exec('SET search_path TO ' . $this->schema);

        (new MigrationRunner($this->pdo, __DIR__ . '/../../migrations'))->run();

        $clients = new PdoClientRepository($this->pdo);
        $this->clientId = $clients->create(new Client(
            id: null,
            name: 'Loja',
            apiKeyHash: hash('sha256', self::API_KEY),
            fromEmail: 'loja@exemplo.com',
            fromName: 'Loja',
            rateLimitPerSec: 10,
            isActive: true,
        ));

        $this->controller = new CreateBatchController(
            new Auth($clients),
            new CreateBatchUseCase(new PdoBatchRepository($this->pdo), new PdoRateLimiter($this->pdo)),
        );
        $this->errors = new ErrorHandler(new JsonLogger(static function (string $line): void {
        }));
    }

    protected function tearDown(): void{
        if (isset($this->schema)) {
            $this->pdo->exec('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
    }

    public function test_it_creates_a_batch_and_returns_202_with_message_ids(): void{
        $response = $this->post($this->payload());

        self::assertSame(202, $response['status']);
        self::assertSame(['batch_id', 'message_ids'], array_keys($response['body']));

        $batchId = (int) ($response['body']['batch_id'] ?? 0);
        $messageIds = $response['body']['message_ids'] ?? [];
        self::assertIsArray($messageIds);

        self::assertSame(1, $this->countRows('batches'));
        self::assertSame('loja@exemplo.com', $this->scalar('SELECT from_email FROM batches WHERE id = ?', [$batchId]));
        self::assertSame(2, $this->countRows('messages'));
        self::assertCount(2, $messageIds);
        self::assertSame($this->clientId, (int) $this->scalar('SELECT client_id FROM messages WHERE id = ?', [$messageIds[0]]));
        self::assertSame(2, $this->countRows('outbox'));
    }

    public function test_it_returns_200_and_the_same_batch_for_a_repeated_idempotency_key(): void{
        $first = $this->post($this->payload(), 'ABC-1');
        $second = $this->post($this->payload(), 'ABC-1');

        self::assertSame(202, $first['status']);
        self::assertSame(200, $second['status']);
        self::assertSame($first['body'], $second['body']);
        self::assertSame(1, $this->countRows('batches'));
        self::assertSame(2, $this->countRows('messages'));
        self::assertSame(2, $this->countRows('outbox'));
    }

    public function test_it_returns_409_when_the_key_is_reused_with_a_different_payload(): void{
        $this->post($this->payload(), 'ABC-1');

        $response = $this->post(['subject' => 'Outro assunto'] + $this->payload(), 'ABC-1');

        self::assertSame(409, $response['status']);
        self::assertSame('conflict', $response['body']['error']['code'] ?? null);
        self::assertSame(1, $this->countRows('batches'));
    }

    public function test_it_returns_200_for_the_same_payload_after_the_content_is_purged(): void{
        $first = $this->post($this->payload(), 'ABC-1');
        $this->purgeContent((int) ($first['body']['batch_id'] ?? 0));

        $second = $this->post($this->payload(), 'ABC-1');

        self::assertSame(200, $second['status']);
        self::assertSame($first['body'], $second['body']);
    }

    public function test_it_returns_409_for_a_different_payload_after_the_content_is_purged(): void{
        $first = $this->post($this->payload(), 'ABC-1');
        $this->purgeContent((int) ($first['body']['batch_id'] ?? 0));

        $response = $this->post(['subject' => 'Outro assunto'] + $this->payload(), 'ABC-1');

        self::assertSame(409, $response['status']);
    }

    public function test_it_returns_400_for_a_body_above_the_json_limit(): void{
        $response = $this->post(str_repeat('a', JsonBody::MAX_BYTES + 1));

        self::assertSame(400, $response['status']);
        self::assertSame('payload too large', $response['body']['error']['message'] ?? null);
    }

    public function test_it_returns_400_for_html_above_the_limit(): void{
        $response = $this->post(['html' => str_repeat('a', 262145)] + $this->payload());

        self::assertSame(400, $response['status']);
        self::assertSame('html is too large', $response['body']['error']['message'] ?? null);
        self::assertSame(0, $this->countRows('batches'));
    }

    public function test_it_returns_429_with_retry_after_when_the_client_is_over_the_limit(): void{
        $this->pdo->beginTransaction();
        $seed = $this->pdo->prepare(
            "INSERT INTO rate_limits (client_id, window_start, hits)
             SELECT id, date_trunc('second', now()), rate_limit_per_sec FROM clients WHERE id = ?"
        );
        $seed->execute([$this->clientId]);

        $response = $this->post($this->payload());

        $this->pdo->rollBack();

        self::assertSame(429, $response['status']);
        self::assertSame('rate_limit_exceeded', $response['body']['error']['code'] ?? null);
        self::assertSame('1', $response['headers']['Retry-After'] ?? null);
        self::assertSame(0, $this->countRows('batches'));
    }

    /** @return array{subject:string, html:string, recipients:list<string>} */
    private function payload(): array{
        return [
            'subject' => 'Seu pedido saiu',
            'html' => '<p>Olá</p>',
            'recipients' => ['a@exemplo.com', 'b@exemplo.com'],
        ];
    }

    /**
     * Mirrors public/index.php: a thrown exception goes through the same ErrorHandler.
     *
     * @param array<string, mixed>|string $body
     * @return array{status:int, body:array<string, mixed>, headers?:array<string, string>}
     */
    private function post(array|string $body, ?string $idempotencyKey = null): array{
        $raw = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR);

        try {
            return $this->controller->create(new Request('Bearer ' . self::API_KEY, $raw, $idempotencyKey));
        } catch (Throwable $exception) {
            return $this->errors->toResponse($exception);
        }
    }

    private function purgeContent(int $batchId): void{
        $this->pdo
            ->prepare("UPDATE batches SET html = '', text = '', html_purged_at = now() WHERE id = ?")
            ->execute([$batchId]);
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
