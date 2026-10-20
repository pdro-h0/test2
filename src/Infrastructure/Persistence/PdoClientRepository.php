<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Port\ClientRepository;
use App\Domain\Client;
use PDO;

final class PdoClientRepository implements ClientRepository {
    public function __construct(private readonly PDO $pdo) {
    }

    public function findByApiKeyHash(string $apiKeyHash): ?Client {
        $statement = $this->pdo->prepare(
            'SELECT id, name, api_key_hash, from_email, from_name, rate_limit_per_sec, active
             FROM clients
             WHERE api_key_hash = ?'
        );
        $statement->execute([$apiKeyHash]);

        /** @var array{id: int, name: string, api_key_hash: string, from_email: string, from_name: string|null, rate_limit_per_sec: int, active: bool}|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return new Client(
            id: $row['id'],
            name: $row['name'],
            apiKeyHash: $row['api_key_hash'],
            fromEmail: $row['from_email'],
            fromName: $row['from_name'],
            rateLimitPerSec: $row['rate_limit_per_sec'],
            isActive: $row['active'],
        );
    }

    public function create(Client $client): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO clients (name, api_key_hash, from_email, from_name, rate_limit_per_sec, active)
             VALUES (:name, :api_key_hash, :from_email, :from_name, :rate_limit_per_sec, :active)
             RETURNING id'
        );
        $statement->bindValue(':name', $client->name);
        $statement->bindValue(':api_key_hash', $client->apiKeyHash);
        $statement->bindValue(':from_email', $client->fromEmail);
        $statement->bindValue(':from_name', $client->fromName);
        $statement->bindValue(':rate_limit_per_sec', $client->rateLimitPerSec, PDO::PARAM_INT);
        // execute() would send false as an empty string; the typed bind sends a real boolean
        $statement->bindValue(':active', $client->isActive, PDO::PARAM_BOOL);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }
}
