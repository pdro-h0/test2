<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Port\Mailer;
use App\Application\Port\MessageRepository;
use App\Domain\Exception\NotFoundException;
use App\Domain\Exception\PermanentDeliveryException;
use App\Domain\Exception\TransientDeliveryException;
// use App\Domain\Message;
use App\Domain\MessageStatus;
use DateTimeImmutable;
use Throwable;

final class SendMessageUseCase {
    private const MAX_ATTEMPTS = 5;
    private const UNEXPECTED_ERROR = 'unexpected error';
    private const CONTENT_EXPIRED = 'message content expired';

    public function __construct(
        private readonly MessageRepository $messages,
        private readonly Mailer $mailer,
    ) {
    }

    public function run(int $messageId): MessageStatus {
        $found = $this->messages->findWithBatch($messageId);
         if (!$found) {
            throw new NotFoundException('message not found');
         }
        
        $message = $found['message'];

        if ($message->getStatus()->isTerminal()) {
            return $message->getStatus();
        }

        if ($found['batch']->htmlPurgedAt !== null) {
            if ($message->getStatus() === MessageStatus::Queued) {
                $message->markSending();
            }

            $message->markFailed(self::CONTENT_EXPIRED);
            $this->messages->save($message);

            return $message->getStatus();
        }

        if ($message->getStatus() === MessageStatus::Queued) {
            $message->markSending();
            $this->messages->save($message);
        }

        try {
            $this->mailer->send($found['batch'], $message->recipient);
        } catch (PermanentDeliveryException $error) {
            $message->markFailed($error->getMessage());
            $this->messages->save($message);

            return $message->getStatus();
        } catch (Throwable $error) {
            $reason = $error instanceof TransientDeliveryException ? $error->getMessage() : self::UNEXPECTED_ERROR;

            if ($message->getAttempts() + 1 >= self::MAX_ATTEMPTS) {
                $message->markFailed($reason);
            } else {
                $message->markForRetry($reason);
            }

            $this->messages->save($message);

            return $message->getStatus();
        }

        $message->markSent(new DateTimeImmutable());
        $this->messages->save($message);

        return $message->getStatus();
    }
}
