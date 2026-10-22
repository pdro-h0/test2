<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Batch;
use App\Infrastructure\Mail\SmtpMailer;
use PHPUnit\Framework\TestCase;

final class SmtpMailerTest extends TestCase {
    private const MAILPIT_API_PORT = 8025;

    private string $host;
    private SmtpMailer $mailer;

    protected function setUp(): void {
        $this->host = self::env('SMTP_HOST');

        if (!in_array($this->host, ['127.0.0.1', 'localhost'], true)) {
            self::markTestSkipped('SMTP_HOST is not local; tests only use Mailpit.');
        }

        $this->mailer = new SmtpMailer($this->host, (int) self::env('SMTP_PORT'), null, null, 'none');
        $this->request('DELETE', '/api/v1/messages');
    }

    public function test_it_delivers_one_email_per_recipient_with_a_single_to(): void {
        $batch = new Batch(
            id: null,
            clientId: 1,
            idempotencyKey: null,
            subject: 'Pedido saiu',
            html: '<p>Olá João</p>',
            text: 'Olá João',
            fromEmail: 'loja@exemplo.com',
        );

        $this->mailer->send($batch, 'a@exemplo.com');
        $this->mailer->send($batch, 'b@exemplo.com');
        $this->mailer->send($batch, 'c@exemplo.com');
        $this->mailer->send($batch, 'd@exemplo.com');

        $messages = $this->messages();
        self::assertCount(3, $messages);

        $recipients = [];
        foreach ($messages as $message) {
            self::assertCount(4, $message['To']);
            $recipients[] = $message['To'][0]['Address'];
            self::assertSame('Pedido saiu', $message['Subject']);
        }

        sort($recipients);
        self::assertSame(['a@exemplo.com', 'b@exemplo.com'], $recipients);

        $detail = $this->message($messages[0]['ID']);
        self::assertStringContainsString('Olá João', $detail['HTML']);
        self::assertStringContainsString('Olá João', $detail['Text']);
    }

    /** @return list<array{ID: string, Subject: string, To: list<array{Address: string}>}> */
    private function messages(): array {
        /** @var array{messages: list<array{ID: string, Subject: string, To: list<array{Address: string}>}>} $list */
        $list = json_decode($this->request('GET', '/api/v1/messages'), true, 512, JSON_THROW_ON_ERROR);

        return $list['messages'];
    }

    /** @return array{HTML: string, Text: string} */
    private function message(string $id): array {
        /** @var array{HTML: string, Text: string} $detail */
        $detail = json_decode($this->request('GET', '/api/v1/message/' . $id), true, 512, JSON_THROW_ON_ERROR);

        return $detail;
    }

    /** @param non-empty-string $method */
    private function request(string $method, string $path): string {
        $handle = curl_init(sprintf('http://%s:%d%s', $this->host, self::MAILPIT_API_PORT, $path));
        self::assertNotFalse($handle);
        curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_TIMEOUT, 5);

        $body = curl_exec($handle);
        self::assertIsString($body);

        return $body;
    }

    private static function env(string $key): string {
        $value = getenv($key);

        if ($value === false || $value === '') {
            self::markTestSkipped(sprintf(
                'Mailpit not configured (%s missing). Run: docker compose up -d mailpit && set -a; . ./.env; set +a',
                $key,
            ));
        }

        return $value;
    }
}
