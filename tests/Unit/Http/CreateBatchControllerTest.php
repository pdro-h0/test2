<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Application\CreateBatchUseCase;
use App\Application\Port\ClientRepository;
use App\Domain\Client;
use App\Domain\Exception\ValidationException;
use App\Http\Auth;
use App\Http\Controller\CreateBatchController;
use App\Http\Request;
use App\Tests\Fake\InMemoryBatchRepository;
use App\Tests\Fake\InMemoryRateLimiter;
use LogicException;
use PHPUnit\Framework\TestCase;

final class CreateBatchControllerTest extends TestCase{
    public function test_it_rejects_a_non_string_subject(): void{
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('subject must be a string');

        $this->controller()->create(new Request(
            'Bearer chave',
            '{"subject":123,"html":"<p>Olá</p>","recipients":["a@exemplo.com"]}',
            null,
        ));
    }

    public function test_it_rejects_recipients_that_are_not_a_list(): void{
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('recipients must be a list');

        $this->controller()->create(new Request(
            'Bearer chave',
            '{"subject":"Oi","html":"<p>Olá</p>","recipients":"a@exemplo.com"}',
            null,
        ));
    }

    private function controller(): CreateBatchController{
        $client = new Client(
            id: 1,
            name: 'Loja',
            apiKeyHash: hash('sha256', 'chave'),
            fromEmail: 'loja@exemplo.com',
            fromName: null,
            rateLimitPerSec: 10,
            isActive: true,
        );

        $clients = new class ($client) implements ClientRepository {
            public function __construct(private readonly Client $client) {
            }

            public function findByApiKeyHash(string $apiKeyHash): ?Client{
                return $this->client->apiKeyHash === $apiKeyHash ? $this->client : null;
            }

            public function create(Client $client): int{
                throw new LogicException('not used in this test');
            }
        };

        return new CreateBatchController(
            new Auth($clients),
            new CreateBatchUseCase(new InMemoryBatchRepository(), new InMemoryRateLimiter()),
        );
    }
}
