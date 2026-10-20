<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Domain\Exception\ConflictException;
use App\Domain\Exception\UnauthorizedException;
use App\Domain\Exception\ValidationException;
use App\Http\ErrorHandler;
use App\Infrastructure\Log\JsonLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ErrorHandlerTest extends TestCase{
    /** @var list<string> */
    private array $lines = [];

    private ErrorHandler $handler;

    protected function setUp(): void{
        $this->handler = new ErrorHandler(new JsonLogger(function (string $line): void {
            $this->lines[] = $line;
        }));
    }

    public function test_it_hides_unexpected_exception_details(): void{
        $response = $this->handler->toResponse(new RuntimeException('SQL: select * from clients'));

        self::assertSame(500, $response['status']);
        self::assertSame(['error' => ['code' => 'internal_error', 'message' => 'internal error']], $response['body']);
        self::assertStringNotContainsString('SQL', json_encode($response['body'], JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(__FILE__, json_encode($response['body'], JSON_THROW_ON_ERROR));
    }

    public function test_it_maps_validation_to_400(): void{
        $response = $this->handler->toResponse(new ValidationException('payload too large'));

        self::assertSame(400, $response['status']);
        self::assertSame(['error' => ['code' => 'validation_error', 'message' => 'payload too large']], $response['body']);
    }

    public function test_it_maps_unauthorized_to_401(): void{
        self::assertSame(401, $this->handler->toResponse(new UnauthorizedException('invalid api key'))['status']);
    }

    public function test_it_maps_conflict_to_409(): void{
        $response = $this->handler->toResponse(new ConflictException('idempotency key reused with a different payload'));

        self::assertSame(409, $response['status']);
        self::assertSame(
            ['error' => ['code' => 'conflict', 'message' => 'idempotency key reused with a different payload']],
            $response['body'],
        );
    }

    public function test_it_logs_4xx_at_info_without_the_message(): void{
        $this->handler->toResponse(new ValidationException('recipient a@exemplo.com is invalid'));

        $line = $this->onlyLine();

        self::assertSame('info', $line['level']);
        self::assertSame(['level' => 'info', 'event' => 'http_error', 'error' => ValidationException::class], $line);
    }

    public function test_it_logs_5xx_at_error(): void{
        $this->handler->toResponse(new RuntimeException('boom'));

        self::assertSame('error', $this->onlyLine()['level']);
    }

    /** @return array<string, mixed> */
    private function onlyLine(): array{
        self::assertCount(1, $this->lines);

        $decoded = json_decode($this->lines[0], true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            self::fail('Log line is not a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
