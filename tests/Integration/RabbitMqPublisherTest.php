<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Infrastructure\Queue\RabbitMqPublisher;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\TestCase;

final class RabbitMqPublisherTest extends TestCase {
    private const RETRY_WAIT_SECONDS = 35;
    private const POLL_MICROSECONDS = 100000;

    private AMQPStreamConnection $connection;
    private AMQPChannel $channel;
    private RabbitMqPublisher $publisher;

    protected function setUp(): void {
        $vhost = self::env('AMQP_TEST_VHOST');
        self::assertStringStartsWith('test_', $vhost, 'Use a dedicated test_ vhost, never the development vhost.');
        self::assertNotSame(getenv('AMQP_VHOST'), $vhost);
        $host = self::env('AMQP_HOST');
        self::assertContains($host, ['127.0.0.1', 'localhost', '::1'], 'RabbitMQ tests require a local instance.');
        $this->connection = new AMQPStreamConnection(
            $host,
            (int) self::env('AMQP_PORT'),
            self::env('AMQP_USER'),
            self::env('AMQP_PASSWORD'),
            $vhost,
        );
        $this->channel = $this->connection->channel();
        $this->publisher = new RabbitMqPublisher($this->channel);
    }

    protected function tearDown(): void {
        if (isset($this->channel) && $this->channel->is_open()) {
            $this->channel->close();
        }
        if (isset($this->connection) && $this->connection->isConnected()) {
            $this->connection->close();
        }
    }

    public function test_it_publishes_and_consumes_the_same_persistent_body(): void {
        $messageId = random_int(1, PHP_INT_MAX);
        $this->publisher->publish($messageId);
        $message = $this->channel->basic_get('emails');

        self::assertInstanceOf(AMQPMessage::class, $message);
        self::assertSame(json_encode(['message_id' => $messageId], JSON_THROW_ON_ERROR), $message->getBody());
        self::assertSame('application/json', $message->get('content_type'));
        self::assertSame(AMQPMessage::DELIVERY_MODE_PERSISTENT, $message->get('delivery_mode'));
        $message->ack();
    }

    public function test_it_declares_durable_queues_and_returns_retry_after_the_fixed_ttl(): void {
        $this->channel->queue_declare('emails', false, true, false, false);
        $this->channel->queue_declare('emails.dlq', false, true, false, false);
        $this->channel->queue_declare('emails.retry', false, true, false, false, false, new AMQPTable([
            'x-message-ttl' => 30000,
            'x-dead-letter-exchange' => '',
            'x-dead-letter-routing-key' => 'emails',
        ]));

        $body = json_encode(['message_id' => random_int(1, PHP_INT_MAX)], JSON_THROW_ON_ERROR);
        $this->channel->basic_publish(new AMQPMessage($body, [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ]), '', 'emails.retry', true);
        $this->channel->wait_for_pending_acks_returns(5);
        $earlyMessage = $this->channel->basic_get('emails');
        self::assertNull($earlyMessage);

        $deadline = microtime(true) + self::RETRY_WAIT_SECONDS;
        do {
            $message = $this->channel->basic_get('emails');
            if ($message !== null) {
                self::assertSame($body, $message->getBody());
                $message->ack();
                return;
            }
            usleep(self::POLL_MICROSECONDS);
        } while (microtime(true) < $deadline);

        self::fail('Retry message did not return to emails before the deadline.');
    }

    private static function env(string $key): string {
        $value = getenv($key);
        if ($value === false || $value === '') {
            self::markTestSkipped(sprintf('RabbitMQ tests not configured (%s missing). See AGENTS.md.', $key));
        }

        return $value;
    }
}
