<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Application\Port\ClientRepository;
use App\Domain\Client;
use App\Domain\Exception\UnauthorizedException;
use App\Http\Auth;
use LogicException;
use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase{
    public function test_it_rejects_missing_authorization_header(): void{
        $auth = new Auth($this->repository($this->client('chave')));

        $this->expectException(UnauthorizedException::class);

        $auth->authenticate(null);
    }

    public function test_it_rejects_a_non_bearer_header(): void{
        $auth = new Auth($this->repository($this->client('chave')));

        $this->expectException(UnauthorizedException::class);

        $auth->authenticate('Basic chave');
    }

    public function test_it_rejects_unknown_key_without_echoing_it(): void{
        $auth = new Auth($this->repository($this->client('chave')));

        try {
            $auth->authenticate('Bearer outra-chave');
            self::fail('Expected UnauthorizedException.');
        } catch (UnauthorizedException $exception) {
            self::assertSame('invalid api key', $exception->getMessage());
        }
    }

    public function test_it_rejects_inactive_client(): void{
        $auth = new Auth($this->repository($this->client('chave', isActive: false)));

        $this->expectException(UnauthorizedException::class);

        $auth->authenticate('Bearer chave');
    }

    public function test_it_returns_client_for_valid_key(): void{
        $client = $this->client('chave');
        $auth = new Auth($this->repository($client));

        self::assertSame($client, $auth->authenticate('Bearer chave'));
    }

    private function client(string $apiKey, bool $isActive = true): Client{
        return new Client(
            id: 1,
            name: 'Loja',
            apiKeyHash: hash('sha256', $apiKey),
            fromEmail: 'loja@exemplo.com',
            fromName: null,
            rateLimitPerSec: 10,
            isActive: $isActive,
        );
    }

    private function repository(Client $client): ClientRepository{
        return new class ($client) implements ClientRepository {
            public function __construct(private readonly Client $client) {
            }

            public function findByApiKeyHash(string $apiKeyHash): ?Client{
                return $this->client->apiKeyHash === $apiKeyHash ? $this->client : null;
            }

            public function create(Client $client): int{
                throw new LogicException('not used in this test');
            }
        };
    }
}
