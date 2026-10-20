<?php

declare(strict_types=1);

namespace App\Http;

final class Request {
    public function __construct(
        public readonly ?string $authorization,
        public readonly string $rawBody,
        public readonly ?string $idempotencyKey,
    ) {
    }

    public static function fromGlobals(): self {
        return new self(
            isset($_SERVER['HTTP_AUTHORIZATION']) ? (string) $_SERVER['HTTP_AUTHORIZATION'] : null,
            (string) file_get_contents('php://input'),
            isset($_SERVER['HTTP_IDEMPOTENCY_KEY']) ? (string) $_SERVER['HTTP_IDEMPOTENCY_KEY'] : null,
        );
    }
}
