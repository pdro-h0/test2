<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

final readonly class Batch {
    public function __construct(
        public ?int $id,
        public int $clientId,
        public ?string $idempotencyKey,
        public string $subject,
        public string $html,
        public string $text,
        public string $fromEmail,
        public ?DateTimeImmutable $htmlPurgedAt = null,
    ) {
    }
}
