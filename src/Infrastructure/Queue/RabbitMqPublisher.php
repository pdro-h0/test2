<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

use App\Application\Exception\PublishException;
use App\Application\Port\Publisher;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPExceptionInterface;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

final class RabbitMqPublisher implements Publisher {
    private const CONFIRM_TIMEOUT_SECONDS = 5;
    // shortcut: fixed retry delay, use per-attempt delays if the SMTP relay requires it.
    private const RETRY_TTL_MILLISECONDS = 30000;

    private bool $isRejected = false;
    private bool $isAvailable = true;

    public function __construct(private readonly AMQPChannel $channel) {
        try {
            $channel->queue_declare('emails', false, true, false, false);
            $channel->queue_declare('emails.retry', false, true, false, false, false, new AMQPTable([
                'x-message-ttl' => self::RETRY_TTL_MILLISECONDS,
                'x-dead-letter-exchange' => '',
                'x-dead-letter-routing-key' => 'emails',
            ]));
            $channel->queue_declare('emails.dlq', false, true, false, false);
            $channel->confirm_select();
            $channel->set_nack_handler(function (): void {
                $this->isRejected = true;
            });
            $channel->set_return_listener(function (): void {
                $this->isRejected = true;
            });
        } catch (AMQPExceptionInterface) {
            throw new PublishException('RabbitMQ publisher initialization failed');
        }
    }

    public function publish(int $messageId): void {
        if (!$this->isAvailable) {
            throw new PublishException('RabbitMQ publisher requires a new channel');
        }

        $this->isRejected = false;
        $message = new AMQPMessage(json_encode(['message_id' => $messageId], JSON_THROW_ON_ERROR), [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ]);

        try {
            $this->channel->basic_publish($message, '', 'emails', true);
            $this->channel->wait_for_pending_acks_returns(self::CONFIRM_TIMEOUT_SECONDS);
        } catch (AMQPExceptionInterface) {
            $this->isAvailable = false;
            throw new PublishException('RabbitMQ publication could not be confirmed');
        }

        if ($this->isRejected()) {
            throw new PublishException('RabbitMQ publication was rejected');
        }
    }

    private function isRejected(): bool {
        return $this->isRejected;
    }
}
