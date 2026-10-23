<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

use App\Application\SendMessageUseCase;
use App\Domain\Exception\NotFoundException;
use App\Domain\MessageStatus;
use App\Infrastructure\Log\JsonLogger;
use JsonException;
use LogicException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use stdClass;

final class RabbitMqWorker {
    private const PREFETCH_COUNT = 5;
    private const WAIT_TIMEOUT_SECONDS = 1;
    private const JSON_MAX_DEPTH = 4;

    public function __construct(
        private readonly AMQPChannel $channel,
        private readonly RabbitMqPublisher $publisher,
        private readonly SendMessageUseCase $sendMessage,
        private readonly JsonLogger $logger,
    ) {
    }

    public function run(): void {
        $this->channel->basic_qos(0, self::PREFETCH_COUNT, false);
        $this->channel->basic_consume('emails', '', false, false, false, false, $this->handle(...));

        while ($this->channel->is_consuming()) {
            try {
                $this->channel->wait(null, false, self::WAIT_TIMEOUT_SECONDS);
            } catch (AMQPTimeoutException) {
                // An idle queue is not a connection failure.
            }
        }
    }

    public function handle(AMQPMessage $delivery): void {
        $messageId = $this->parseMessageId($delivery->getBody());
        if ($messageId === null) {
            $this->publisher->publishInvalid();
            $delivery->ack();
            $this->logger->info('worker_invalid_payload');
            return;
        }

        try {
            // shortcut: at-least-once delivery, a crash after SMTP accepts but before the sent save resends; exactly-once needs a send ledger.
            $status = $this->sendMessage->run($messageId);
        } catch (NotFoundException) {
            $this->publisher->publishFailed($messageId);
            $delivery->ack();
            $this->logger->info('worker_message_not_found', ['message_id' => $messageId]);
            return;
        }

        // shortcut: concurrent deliveries can send twice, add a per-message claim before scaling workers.
        $event = match ($status) {
            MessageStatus::Sent => 'message_sent',
            MessageStatus::Queued => 'message_retry_scheduled',
            MessageStatus::Failed => 'message_dead_lettered',
            MessageStatus::Sending => throw new LogicException('Unexpected sending result'),
        };

        if ($status === MessageStatus::Queued) {
            $this->publisher->publishRetry($messageId);
        } elseif ($status === MessageStatus::Failed) {
            $this->publisher->publishFailed($messageId);
        }

        // shortcut: a crash before ack can repeat forwarding, deduplicate if DLQ consumers require it.
        $delivery->ack();
        $this->logger->info($event, ['message_id' => $messageId]);
    }

    private function parseMessageId(string $body): ?int {
        try {
            $payload = json_decode($body, false, self::JSON_MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!$payload instanceof stdClass || array_keys(get_object_vars($payload)) !== ['message_id']) {
            return null;
        }

        $messageId = $payload->message_id;

        return is_int($messageId) && $messageId > 0 ? $messageId : null;
    }
}
