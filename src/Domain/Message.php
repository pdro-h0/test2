<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Exception\InvalidTransitionException;
use DateTimeImmutable;

final class Message {
    public function __construct(
        public readonly ?int $id,
        public readonly int $batchId,
        public readonly int $clientId,
        public readonly string $recipient,
        public readonly DateTimeImmutable $createdAt,
        private MessageStatus $status = MessageStatus::Queued,
        private int $attempts = 0,
        private ?string $lastError = null,
        private ?DateTimeImmutable $sentAt = null,
    ) {
    }

    public function getStatus(): MessageStatus {
        return $this->status;
    }

    public function getAttempts(): int {
        return $this->attempts;
    }

    public function getLastError(): ?string {
        return $this->lastError;
    }

    public function getSentAt(): ?DateTimeImmutable {
        return $this->sentAt;
    }

    public function markSending(): void {
        $this->transitionTo(MessageStatus::Sending);
    }

    public function markSent(DateTimeImmutable $sentAt): void {
        $this->transitionTo(MessageStatus::Sent);
        $this->sentAt = $sentAt;
    }

    public function markForRetry(string $error): void {
        $this->transitionTo(MessageStatus::Queued);
        $this->attempts++;
        $this->lastError = $error;
    }

    public function markFailed(string $error): void {
        $this->transitionTo(MessageStatus::Failed);
        $this->attempts++;
        $this->lastError = $error;
    }

    private function transitionTo(MessageStatus $next): void {
        if (!$this->status->canTransitionTo($next)) {
            throw new InvalidTransitionException(sprintf(
                'Cannot transition message from %s to %s.',
                $this->status->value,
                $next->value
            ));
        }

        $this->status = $next;
    }
}
