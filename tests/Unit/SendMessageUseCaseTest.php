<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Application\SendMessageUseCase;
use App\Domain\Batch;
use App\Domain\Exception\NotFoundException;
use App\Domain\Exception\PermanentDeliveryException;
use App\Domain\Exception\TransientDeliveryException;
use App\Domain\Message;
use App\Domain\MessageStatus;
use App\Tests\Fake\InMemoryMessageRepository;
use App\Tests\Fake\RecordingMailer;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SendMessageUseCaseTest extends TestCase {
    private InMemoryMessageRepository $messages;
    private RecordingMailer $mailer;
    private SendMessageUseCase $sut;

    protected function setUp(): void {
        $this->messages = new InMemoryMessageRepository();
        $this->mailer = new RecordingMailer();
        $this->sut = new SendMessageUseCase($this->messages, $this->mailer);
    }

    public function test_it_sends_one_message_and_marks_it_sent(): void {
        $this->givenMessage();

        $status = $this->sut->run(10);

        self::assertSame(MessageStatus::Sent, $status);
        self::assertSame(['a@exemplo.com'], $this->mailer->recipients);
        self::assertSame(MessageStatus::Sent, $this->message()->getStatus());
        self::assertNotNull($this->message()->getSentAt());
    }

    public function test_it_marks_a_permanent_failure_as_failed(): void {
        $this->givenMessage();
        $this->mailer->error = new PermanentDeliveryException('SMTP 550');

        $status = $this->sut->run(10);

        self::assertSame(MessageStatus::Failed, $status);
        self::assertSame(1, $this->message()->getAttempts());
        self::assertSame('SMTP 550', $this->message()->getLastError());
    }

    public function test_it_requeues_a_transient_failure(): void {
        $this->givenMessage();
        $this->mailer->error = new TransientDeliveryException('SMTP 451');

        $status = $this->sut->run(10);

        self::assertSame(MessageStatus::Queued, $status);
        self::assertSame(1, $this->message()->getAttempts());
        self::assertSame('SMTP 451', $this->message()->getLastError());
    }

    public function test_it_marks_failed_after_the_fifth_failure(): void {
        $this->givenMessage(status: MessageStatus::Sending, attempts: 4);
        $this->mailer->error = new TransientDeliveryException('SMTP 451');

        $status = $this->sut->run(10);

        self::assertSame(MessageStatus::Failed, $status);
        self::assertSame(5, $this->message()->getAttempts());
    }

    public function test_it_does_not_send_a_message_that_was_already_sent(): void {
        $this->givenMessage(status: MessageStatus::Sent);

        $status = $this->sut->run(10);

        self::assertSame(MessageStatus::Sent, $status);
        self::assertSame([], $this->mailer->recipients);
    }

    public function test_it_fails_a_queued_message_with_purged_content_without_sending(): void {
        $this->givenMessage(htmlPurgedAt: new DateTimeImmutable('2026-10-20T00:00:00+00:00'));

        $status = $this->sut->run(10);

        self::assertSame(MessageStatus::Failed, $status);
        self::assertSame([], $this->mailer->recipients);
        self::assertSame(1, $this->message()->getAttempts());
        self::assertSame('message content expired', $this->message()->getLastError());
    }

    public function test_it_fails_a_sending_message_with_purged_content_without_sending(): void {
        $this->givenMessage(
            status: MessageStatus::Sending,
            attempts: 3,
            htmlPurgedAt: new DateTimeImmutable('2026-10-20T00:00:00+00:00'),
        );

        $status = $this->sut->run(10);

        self::assertSame(MessageStatus::Failed, $status);
        self::assertSame([], $this->mailer->recipients);
        self::assertSame(4, $this->message()->getAttempts());
        self::assertSame('message content expired', $this->message()->getLastError());
    }

    public function test_it_keeps_a_terminal_message_with_purged_content_unchanged(): void {
        $this->givenMessage(
            status: MessageStatus::Failed,
            attempts: 2,
            lastError: 'SMTP 550',
            htmlPurgedAt: new DateTimeImmutable('2026-10-20T00:00:00+00:00'),
        );

        $status = $this->sut->run(10);

        self::assertSame(MessageStatus::Failed, $status);
        self::assertSame([], $this->mailer->recipients);
        self::assertSame(2, $this->message()->getAttempts());
        self::assertSame('SMTP 550', $this->message()->getLastError());
    }

    public function test_it_treats_an_unknown_error_as_transient_without_detail(): void {
        $this->givenMessage();
        $this->mailer->error = new RuntimeException('boom: secret detail');

        $status = $this->sut->run(10);

        self::assertSame(MessageStatus::Queued, $status);
        self::assertSame(1, $this->message()->getAttempts());
        self::assertSame('unexpected error', $this->message()->getLastError());
    }

    public function test_it_throws_not_found_for_an_unknown_id(): void {
        $this->expectException(NotFoundException::class);

        $this->sut->run(999);
    }

    private function givenMessage(
        MessageStatus $status = MessageStatus::Queued,
        int $attempts = 0,
        ?string $lastError = null,
        ?DateTimeImmutable $htmlPurgedAt = null,
    ): void {
        $this->messages->messages[10] = new Message(
            id: 10,
            batchId: 1,
            clientId: 1,
            recipient: 'a@exemplo.com',
            createdAt: new DateTimeImmutable('2026-10-21T12:00:00+00:00'),
            status: $status,
            attempts: $attempts,
            lastError: $lastError,
        );

        $this->messages->batches[10] = new Batch(
            id: 1,
            clientId: 1,
            idempotencyKey: null,
            subject: 'Seu pedido saiu',
            html: '<p>Ola</p>',
            text: 'Ola',
            fromEmail: 'loja@exemplo.com',
            htmlPurgedAt: $htmlPurgedAt,
        );
    }

    private function message(): Message {
        return $this->messages->messages[10];
    }
}
