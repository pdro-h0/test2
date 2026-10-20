<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Client;

interface ClientRepository {
    public function findByApiKeyHash(string $apiKeyHash): ?Client;
    public function create(Client $client): int;
}
