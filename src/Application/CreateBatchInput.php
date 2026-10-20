<?php

declare(strict_types=1);

namespace App\Application;

final readonly class CreateBatchInput {
    /** @param array<array-key, mixed> $recipients validated by CreateBatchUseCase, JSON gives no types */
    public function __construct(
        public string $subject,
        public string $html,
        public ?string $text,
        public ?string $from,
        public array $recipients,
        public ?string $idempotencyKey,
    ) {
    }
}
