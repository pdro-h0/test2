<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\CreateBatchUseCase;
use App\Application\GetMessageUseCase;
use App\Domain\Batch;
use App\Domain\Client;
use App\Http\Auth;
use App\Http\Controller\CreateBatchController;
use App\Http\Controller\GetMessageController;
use App\Http\Dispatcher;
use App\Http\ErrorHandler;
use App\Http\Request;
use App\Http\Routes;
use App\Infrastructure\Log\JsonLogger;
use App\Infrastructure\Persistence\MigrationRunner;
use App\Infrastructure\Persistence\PdoBatchRepository;
use App\Infrastructure\Persistence\PdoClientRepository;
use App\Infrastructure\Persistence\PdoMessageRepository;
use App\Tests\Fake\InMemoryRateLimiter;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

final class GetMessageControllerTest extends TestCase {
    private const OWNER_KEY = 'chave-dono';
    private const OTHER_KEY = 'chave-outro';

    private PDO $pdo;
    private string $schema;
    private Dispatcher $dispatcher;
    private ErrorHandler $errors;
    private int $messageId;

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

        $this->schema = 'get_message_test_' . bin2hex(random_bytes(4));
        $this->pdo->exec('CREATE SCHEMA ' . $this->schema);
        $this->pdo->exec('SET search_path TO ' . $this->schema);

        (new MigrationRunner($this->pdo, __DIR__ . '/../../migrations'))->run();

        $clients = new PdoClientRepository($this->pdo);
        $ownerId = $this->createClient($clients, 'Loja', self::OWNER_KEY);
        $this->createClient($clients, 'Outra', self::OTHER_KEY);

        $batches = new PdoBatchRepository($this->pdo);
        $saved = $batches->save(new Batch(
            id: null,
            clientId: $ownerId,
            idempotencyKey: null,
            subject: 'Seu pedido saiu',
            html: '<p>Olá</p>',
            text: 'Olá',
            fromEmail: 'loja@exemplo.com',
        ), ['a@exemplo.com'], str_repeat('a', 64));
        $this->messageId = $saved['message_ids'][0];

        $this->dispatcher = new Dispatcher();
        $auth = new Auth($clients);
        Routes::register(
            $this->dispatcher,
            new CreateBatchController(
                $auth,
                new CreateBatchUseCase($batches, new InMemoryRateLimiter()),
            ),
            new GetMessageController($auth, new GetMessageUseCase(new PdoMessageRepository($this->pdo))),
        );
        $this->errors = new ErrorHandler(new JsonLogger(static function (string $line): void {
        }));
    }

    protected function tearDown(): void{
        if (isset($this->schema)) {
            $this->pdo->exec('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
    }

    public function test_it_returns_the_status_of_the_clients_message(): void{
        $response = $this->get('/messages/' . $this->messageId, self::OWNER_KEY);

        self::assertSame(200, $response['status']);
        self::assertSame(['status', 'attempts', 'last_error', 'created_at', 'sent_at'], array_keys($response['body']));
        self::assertSame('queued', $response['body']['status']);
        self::assertSame(0, $response['body']['attempts']);
        self::assertNull($response['body']['last_error']);
        self::assertNull($response['body']['sent_at']);
        self::assertIsString($response['body']['created_at']);
    }

    public function test_it_returns_404_for_another_clients_message(): void{
        $response = $this->get('/messages/' . $this->messageId, self::OTHER_KEY);

        self::assertSame(404, $response['status']);
        self::assertSame('not_found', $response['body']['error']['code'] ?? null);
    }

    public function test_it_returns_404_for_an_unknown_id(): void{
        $response = $this->get('/messages/999999', self::OWNER_KEY);

        self::assertSame(404, $response['status']);
    }

    public function test_it_returns_404_for_a_non_numeric_id(): void{
        self::assertSame(404, $this->get('/messages/abc', self::OWNER_KEY)['status']);
        self::assertSame(404, $this->get('/messages/' . $this->messageId . 'abc', self::OWNER_KEY)['status']);
    }

    /** @return array{status:int, body:array<string, mixed>} */
    private function get(string $path, string $apiKey): array{
        try {
            return $this->dispatcher->dispatch('GET', $path, new Request('Bearer ' . $apiKey, '', null));
        } catch (Throwable $exception) {
            return $this->errors->toResponse($exception);
        }
    }

    private function createClient(PdoClientRepository $clients, string $name, string $apiKey): int{
        return $clients->create(new Client(
            id: null,
            name: $name,
            apiKeyHash: hash('sha256', $apiKey),
            fromEmail: strtolower($name) . '@exemplo.com',
            fromName: $name,
            rateLimitPerSec: 10,
            isActive: true,
        ));
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
