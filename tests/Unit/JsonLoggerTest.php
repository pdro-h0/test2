<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Infrastructure\Log\JsonLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JsonLoggerTest extends TestCase{
    /** @var resource */
    private $stream;

    private JsonLogger $logger;

    protected function setUp(): void{
        $stream = fopen('php://memory', 'w+');

        if ($stream === false) {
            self::fail('Cannot open memory stream.');
        }

        $this->stream = $stream;
        $this->logger = new JsonLogger(function (string $line): void {
            fwrite($this->stream, $line . "\n");
        });
    }

    protected function tearDown(): void{
        fclose($this->stream);
    }

    public function test_it_writes_one_valid_json_line(): void{
        $this->logger->info('batch_created', ['batch_id' => 7, 'message_id' => 8, 'client_id' => 9]);

        $output = $this->decodedOutput();

        self::assertSame('info', $output['level']);
        self::assertSame('batch_created', $output['event']);
        self::assertSame(7, $output['batch_id']);
        self::assertSame(8, $output['message_id']);
        self::assertSame(9, $output['client_id']);
        self::assertSame(1, substr_count($this->rawOutput(), "\n"));
    }

    public function test_it_drops_html_and_recipient_from_the_context(): void{
        $this->logger->info('message_sent', [
            'message_id' => 8,
            'html' => '<p>Olá</p>',
            'recipient' => 'a@exemplo.com',
        ]);

        $raw = $this->rawOutput();

        self::assertStringNotContainsString('Olá', $raw);
        self::assertStringNotContainsString('a@exemplo.com', $raw);
        self::assertSame(
            ['level' => 'info', 'event' => 'message_sent', 'message_id' => 8],
            $this->decodedOutput()
        );
    }

    public function test_it_writes_error_with_class_and_message(): void{
        $this->logger->error('send_failed', new RuntimeException('smtp timeout'), ['message_id' => 8]);

        $output = $this->decodedOutput();

        self::assertSame('error', $output['level']);
        self::assertSame('send_failed', $output['event']);
        self::assertSame(8, $output['message_id']);
        self::assertSame(RuntimeException::class . ': smtp timeout', $output['error']);
    }

    public function test_caller_cannot_overwrite_level_and_event(): void{
        $this->logger->info('batch_created', ['level' => 'error', 'event' => 'fake']);

        $output = $this->decodedOutput();

        self::assertSame('info', $output['level']);
        self::assertSame('batch_created', $output['event']);
    }

    private function rawOutput(): string{
        rewind($this->stream);
        $raw = stream_get_contents($this->stream);

        if (!is_string($raw)) {
            self::fail('Cannot read memory stream.');
        }

        return $raw;
    }

    /** @return array<string, mixed> */
    private function decodedOutput(): array{
        $decoded = json_decode($this->rawOutput(), true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            self::fail('Log line is not a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
