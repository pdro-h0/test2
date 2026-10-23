<?php

declare(strict_types=1);

namespace App\Tests\Unit\Queue;

use App\Application\Exception\PublishException;
use App\Infrastructure\Queue\RabbitMqPublisher;
use Closure;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class RabbitMqPublisherTest extends TestCase {
    private AMQPChannel&MockObject $channel;
    private Closure $reject;
    private Closure $returnMessage;

    protected function setUp(): void {
        $this->channel = $this->createMock(AMQPChannel::class);
        $this->channel->method('set_nack_handler')->willReturnCallback(function (callable $callback): void {
            $this->reject = Closure::fromCallable($callback);
        });
        $this->channel->method('set_return_listener')->willReturnCallback(function (callable $callback): void {
            $this->returnMessage = Closure::fromCallable($callback);
        });
    }

    public function test_it_publishes_persistent_json_and_waits_for_confirmation(): void {
        $this->channel->expects(self::once())->method('confirm_select');
        $this->channel->expects(self::once())->method('basic_publish')->with(
            self::callback(static fn (AMQPMessage $message): bool =>
                $message->getBody() === '{"message_id":456}'
                && $message->get('content_type') === 'application/json'
                && $message->get('delivery_mode') === AMQPMessage::DELIVERY_MODE_PERSISTENT),
            '',
            'emails',
            true,
        );
        $this->channel->expects(self::once())->method('wait_for_pending_acks_returns')->with(5);

        (new RabbitMqPublisher($this->channel))->publish(456);
    }

    public function test_it_publishes_a_retry_as_persistent_json_and_waits_for_confirmation(): void {
        $this->channel->expects(self::once())->method('basic_publish')->with(
            self::callback(static fn (AMQPMessage $message): bool =>
                $message->getBody() === '{"message_id":456}'
                && $message->get('content_type') === 'application/json'
                && $message->get('delivery_mode') === AMQPMessage::DELIVERY_MODE_PERSISTENT),
            '',
            'emails.retry',
            true,
        );
        $this->channel->expects(self::once())->method('wait_for_pending_acks_returns')->with(5);

        (new RabbitMqPublisher($this->channel))->publishRetry(456);
    }

    public function test_it_publishes_a_dead_letter_as_persistent_json_and_waits_for_confirmation(): void {
        $this->channel->expects(self::once())->method('basic_publish')->with(
            self::callback(static fn (AMQPMessage $message): bool =>
                $message->getBody() === '{"message_id":456}'
                && $message->get('content_type') === 'application/json'
                && $message->get('delivery_mode') === AMQPMessage::DELIVERY_MODE_PERSISTENT),
            '',
            'emails.dlq',
            true,
        );
        $this->channel->expects(self::once())->method('wait_for_pending_acks_returns')->with(5);

        (new RabbitMqPublisher($this->channel))->publishFailed(456);
    }

    public function test_it_publishes_an_invalid_payload_without_copying_the_original_body(): void {
        $this->channel->expects(self::once())->method('basic_publish')->with(
            self::callback(static fn (AMQPMessage $message): bool =>
                $message->getBody() === '{"error":"invalid_payload"}'
                && $message->get('delivery_mode') === AMQPMessage::DELIVERY_MODE_PERSISTENT),
            '',
            'emails.dlq',
            true,
        );

        (new RabbitMqPublisher($this->channel))->publishInvalid();
    }

    #[DataProvider('rejections')]
    public function test_it_rejects_nacks_and_returns_without_contaminating_the_next_publish(string $kind): void {
        $publisher = new RabbitMqPublisher($this->channel);
        $calls = 0;
        $this->channel->method('wait_for_pending_acks_returns')->willReturnCallback(function () use ($kind, &$calls): void {
            if ($calls++ === 0) {
                ($kind === 'nack' ? $this->reject : $this->returnMessage)();
            }
        });

        try {
            $publisher->publish(456);
            self::fail('Expected publication rejection.');
        } catch (PublishException $exception) {
            self::assertSame('RabbitMQ publication was rejected', $exception->getMessage());
        }

        $publisher->publish(457);
        self::assertSame(2, $calls);
    }

    /** @return array<string, array{string}> */
    public static function rejections(): array {
        return ['negative confirmation' => ['nack'], 'unroutable message' => ['return']];
    }

    /** @param class-string<AMQPTimeoutException|AMQPChannelClosedException> $exception_class */
    #[DataProvider('failures')]
    public function test_it_hides_transport_errors_and_requires_a_new_channel(string $method, string $exception_class): void {
        $exception = new $exception_class('secret-password');
        $this->channel->method($method)->willThrowException($exception);
        $publisher = new RabbitMqPublisher($this->channel);

        try {
            $publisher->publish(456);
            self::fail('Expected publication failure.');
        } catch (PublishException $error) {
            self::assertSame('RabbitMQ publication could not be confirmed', $error->getMessage());
            self::assertNull($error->getPrevious());
        }

        $this->expectExceptionMessage('RabbitMQ publisher requires a new channel');
        $publisher->publish(457);
    }

    /** @return array<string, array{string, class-string<AMQPTimeoutException|AMQPChannelClosedException>}> */
    public static function failures(): array {
        return [
            'timeout' => ['wait_for_pending_acks_returns', AMQPTimeoutException::class],
            'closed channel' => ['basic_publish', AMQPChannelClosedException::class],
        ];
    }

    public function test_it_hides_initialization_errors(): void {
        $this->channel->method('queue_declare')->willThrowException(new AMQPChannelClosedException('secret-password'));
        $this->expectException(PublishException::class);
        $this->expectExceptionMessage('RabbitMQ publisher initialization failed');

        new RabbitMqPublisher($this->channel);
    }
}
