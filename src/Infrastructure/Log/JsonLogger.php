<?php

declare(strict_types=1);

namespace App\Infrastructure\Log;

use Closure;
use Throwable;

final class JsonLogger{
    private const FIELDS = ['batch_id', 'message_id', 'client_id', 'error'];

    /** @param Closure(string): void $writer */
    public function __construct(private readonly Closure $writer) {
    }

    public static function stdout(): self{
        return new self(static function (string $line): void {
            // Writing to php://stdout works under php-fpm too; the STDOUT constant is CLI-only.
            file_put_contents('php://stdout', $line . "\n");
        });
    }

    /** @param array<string, mixed> $context */
    public function info(string $event, array $context = []): void{
        $this->write('info', $event, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $event, Throwable $exception, array $context = []): void{
        $error = $exception::class . ': ' . $exception->getMessage();

        $this->write('error', $event, ['error' => $error] + $context);
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $event, array $context): void{
        // Allowlist: anything the caller adds beyond these keys never reaches stdout.
        $line = json_encode(
            ['level' => $level, 'event' => $event] + array_intersect_key($context, array_flip(self::FIELDS)),
            JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );

        ($this->writer)($line);
    }
}
