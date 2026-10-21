<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Exception\ConflictException;
use App\Domain\Exception\MethodNotAllowedException;
use App\Domain\Exception\NotFoundException;
use App\Domain\Exception\UnauthorizedException;
use App\Domain\Exception\ValidationException;
use App\Infrastructure\Log\JsonLogger;
use Throwable;

final class ErrorHandler{
    public function __construct(private readonly JsonLogger $logger) {
    }

    /** @return array{status:int, body:array{error:array{code:string, message:string}}} */
    public function toResponse(Throwable $exception): array{
        [$statusCode, $statusText, $message] = $this->map($exception);

        if ($statusCode >= 500) {
            $this->logger->error('http_error', $exception);
        } else {
            // Class only: exception messages must never carry payload, recipient or key.
            $this->logger->info('http_error', ['error' => $exception::class]);
        }

        return ['status' => $statusCode, 'body' => ['error' => ['code' => $statusText, 'message' => $message]]];
    }

    /** @return array{int, string, string} */
    private function map(Throwable $exception): array{
        return match (true) {
            $exception instanceof UnauthorizedException => [401, 'unauthorized', $exception->getMessage()],
            $exception instanceof ValidationException => [400, 'validation_error', $exception->getMessage()],
            $exception instanceof ConflictException => [409, 'conflict', $exception->getMessage()],
            $exception instanceof NotFoundException => [404, 'not_found', $exception->getMessage()],
            $exception instanceof MethodNotAllowedException => [405, 'method_not_allowed', 'method not allowed'],
            default => [500, 'internal_error', 'internal error'],
        };
    }
}
