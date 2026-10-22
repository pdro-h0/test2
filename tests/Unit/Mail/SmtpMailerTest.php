<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mail;

use App\Domain\Batch;
use App\Domain\Exception\TransientDeliveryException;
use App\Infrastructure\Mail\SmtpMailer;
use PHPUnit\Framework\TestCase;

final class SmtpMailerTest extends TestCase {
    public function test_it_throws_transient_and_hides_recipient_and_password_when_unreachable(): void {
        $mailer = new SmtpMailer('127.0.0.1', $this->closedPort(), 'usuario', 'senha-secreta', 'none');

        try {
            $mailer->send($this->batch(), 'vitima@exemplo.com');
            self::fail('Expected a transient delivery exception.');
        } catch (TransientDeliveryException $exception) {
            self::assertStringNotContainsString('vitima@exemplo.com', $exception->getMessage());
            self::assertStringNotContainsString('senha-secreta', $exception->getMessage());
        }
    }

    public function test_it_rejects_an_unknown_encryption_value(): void {
        $this->expectException(\InvalidArgumentException::class);

        new SmtpMailer('127.0.0.1', 0, null, null, 'TLs');
    }

    private function closedPort(): int {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($server);

        $address = stream_socket_get_name($server, false);
        fclose($server);
        self::assertIsString($address);

        return (int) substr($address, strrpos($address, ':') + 1);
    }

    private function batch(): Batch {
        return new Batch(
            id: null,
            clientId: 1,
            idempotencyKey: null,
            subject: 'Pedido saiu',
            html: '<p>Olá</p>',
            text: 'Olá',
            fromEmail: 'loja@exemplo.com',
        );
    }
}
