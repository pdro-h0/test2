<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Port\ClientRepository;
use App\Domain\Client;
use App\Domain\Exception\UnauthorizedException;

final class Auth{
    public function __construct(private readonly ClientRepository $clients) {
    }

    public function authenticate(?string $authorization): Client{
        if ($authorization === null || preg_match('/^Bearer\s+(\S+)$/i', $authorization, $matches) !== 1) {
            throw new UnauthorizedException('invalid api key');
        }

        $client = $this->clients->findByApiKeyHash(hash('sha256', $matches[1]));

        if ($client === null || !$client->isActive) {
            throw new UnauthorizedException('invalid api key');
        }

        return $client;
    }
}
