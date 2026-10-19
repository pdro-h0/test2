<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class Client {
    public function __construct(
        public ?int $id,
        public string $name,
        public string $apiKeyHash,
        public string $fromEmail,
        public ?string $fromName,
        public int $rateLimitPerSec,
        public bool $isActive,
    ) {
    }
}
