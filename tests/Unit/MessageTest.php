<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Exception\InvalidTransitionException;
use App\Domain\Message;
use App\Domain\MessageStatus;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase{
    public function test_it_goes_from_queued_to_sent_and_becomes_terminal(): void{
        $message = $this->queuedMessage();

        $message->markSending();
        $message->markSent(new DateTimeImmutable('2026-01-01T00:01:00+00:00'));

        self::assertSame(MessageStatus::Sent, $message->getStatus());
        self::assertTrue($message->getStatus()->isTerminal());
        self::assertSame('2026-01-01T00:01:00+00:00', $message->getSentAt()?->format(DATE_ATOM));
        self::assertSame(0, $message->getAttempts());
    }

    public function test_it_records_a_permanent_failure(): void{
        $message = $this->queuedMessage();

        $message->markSending();
        $message->markFailed('550 mailbox unavailable');

        self::assertSame(MessageStatus::Failed, $message->getStatus());
        self::assertTrue($message->getStatus()->isTerminal());
        self::assertSame(1, $message->getAttempts());
        self::assertSame('550 mailbox unavailable', $message->getLastError());
        self::assertNull($message->getSentAt());
    }

    public function test_it_returns_to_queued_on_a_transient_failure(): void{
        $message = $this->queuedMessage();

        $message->markSending();
        $message->markForRetry('451 try again later');

        self::assertSame(MessageStatus::Queued, $message->getStatus());
        self::assertSame(1, $message->getAttempts());
        self::assertSame('451 try again later', $message->getLastError());
    }

    public function test_it_rejects_sent_to_queued_and_keeps_the_status(): void{
        $message = $this->queuedMessage();
        $message->markSending();
        $message->markSent(new DateTimeImmutable('2026-01-01T00:01:00+00:00'));

        try {
            $message->markForRetry('late error');
            self::fail('Expected InvalidTransitionException.');
        } catch (InvalidTransitionException) {
            self::assertSame(MessageStatus::Sent, $message->getStatus());
            self::assertNull($message->getLastError());
        }
    }

    public function test_it_rejects_failed_to_sending(): void{
        $message = $this->queuedMessage();
        $message->markSending();
        $message->markFailed('550 mailbox unavailable');

        $this->expectException(InvalidTransitionException::class);

        $message->markSending();
    }

    public function test_it_counts_five_attempts_before_the_final_failure(): void{
        $message = $this->queuedMessage();

        for ($i = 0; $i < 4; $i++) {
            $message->markSending();
            $message->markForRetry('451 try again later');
        }

        $message->markSending();
        $message->markFailed('451 too many retries');

        self::assertSame(5, $message->getAttempts());
        self::assertSame(MessageStatus::Failed, $message->getStatus());
    }

    private function queuedMessage(): Message{
        return new Message(
            id: 1,
            batchId: 2,
            clientId: 3,
            recipient: 'a@exemplo.com',
            createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
    }
}
