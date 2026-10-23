<?php

declare(strict_types=1);

namespace App\Tests\Unit\Queue;

use App\Application\Exception\PublishException;
use App\Application\SendMessageUseCase;
use App\Domain\Batch;
use App\Domain\Exception\PermanentDeliveryException;
use App\Domain\Exception\TransientDeliveryException;
use App\Domain\Message;
use App\Domain\MessageStatus;
use App\Infrastructure\Log\JsonLogger;
use App\Infrastructure\Queue\RabbitMqPublisher;
use App\Infrastructure\Queue\RabbitMqWorker;
use App\Tests\Fake\InMemoryMessageRepository;
use App\Tests\Fake\RecordingMailer;
use Closure;
use DateTimeImmutable;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class RabbitMqWorkerTest extends TestCase {
    private const MESSAGE_ID = 10;
    private const RECIPIENT = 'secret-recipient@exemplo.com';

    private AMQPChannel&MockObject $channel;
    private InMemoryMessageRepository $messages;
    private RecordingMailer $mailer;
    private RabbitMqWorker $worker;

    /** @var list<array{0: string, 1: string|int, 2?: string}> */
    private array $calls = [];

    /** @var list<string> */
    private array $logs = [];

    private ?Closure $reject = null;

    protected function setUp(): void {
        $this->channel = $this->createMock(AMQPChannel::class);
        $this->channel->method('set_nack_handler')->willReturnCallback(function (callable $callback): void {
            $this->reject = Closure::fromCallable($callback);
        });
        $this->channel->method('set_return_listener')->willReturnCallback(static function (callable $callback): void {
        });
        $this->channel->method('basic_publish')->willReturnCallback(
            function (AMQPMessage $message, string $exchange, string $queue, bool $mandatory = false): void {
                $this->calls[] = ['publish', $queue, $message->getBody()];
            },
        );
        $this->channel->method('basic_ack')->willReturnCallback(function (int $deliveryTag, bool $multiple = false): void {
            $this->calls[] = ['ack', $deliveryTag];
        });

        $this->messages = new InMemoryMessageRepository();
        $this->mailer = new RecordingMailer();
        $this->worker = new RabbitMqWorker(
            $this->channel,
            new RabbitMqPublisher($this->channel),
            new SendMessageUseCase($this->messages, $this->mailer),
            new JsonLogger(function (string $line): void {
                $this->logs[] = $line;
            }),
        );
    }

    public function test_it_acknowledges_a_sent_message_without_forwarding(): void {
        $this->givenMessage();

        $this->worker->handle($this->delivery('{"message_id":10}'));

        self::assertSame([['ack', 1]], $this->calls);
        self::assertSame([self::RECIPIENT], $this->mailer->recipients);
        self::assertSame('message_sent', $this->loggedEvent());
    }

    public function test_it_publishes_a_retry_before_acknowledging(): void {
        $this->givenMessage();
        $this->mailer->error = new TransientDeliveryException('SMTP 451');

        $this->worker->handle($this->delivery('{"message_id":10}'));

        self::assertSame([
            ['publish', 'emails.retry', '{"message_id":10}'],
            ['ack', 1],
        ], $this->calls);
        self::assertSame(MessageStatus::Queued, $this->messages->messages[self::MESSAGE_ID]->getStatus());
        self::assertSame('message_retry_scheduled', $this->loggedEvent());
    }

    public function test_it_publishes_a_dead_letter_before_acknowledging(): void {
        $this->givenMessage();
        $this->mailer->error = new PermanentDeliveryException('SMTP 550');

        $this->worker->handle($this->delivery('{"message_id":10}'));

        self::assertSame([
            ['publish', 'emails.dlq', '{"message_id":10}'],
            ['ack', 1],
        ], $this->calls);
        self::assertSame(MessageStatus::Failed, $this->messages->messages[self::MESSAGE_ID]->getStatus());
        self::assertSame('message_dead_lettered', $this->loggedEvent());
    }

    public function test_it_dead_letters_expired_content_before_acknowledging(): void {
        $this->givenMessage(htmlPurged: true);

        $this->worker->handle($this->delivery('{"message_id":10}'));

        self::assertSame([
            ['publish', 'emails.dlq', '{"message_id":10}'],
            ['ack', 1],
        ], $this->calls);
        self::assertSame([], $this->mailer->recipients);
        self::assertSame(MessageStatus::Failed, $this->messages->messages[self::MESSAGE_ID]->getStatus());
        self::assertSame('message content expired', $this->messages->messages[self::MESSAGE_ID]->getLastError());
        self::assertSame('message_dead_lettered', $this->loggedEvent());
    }

    public function test_a_rejected_publication_prevents_the_acknowledgement(): void {
        $this->givenMessage();
        $this->mailer->error = new TransientDeliveryException('SMTP 451');
        $this->channel->method('wait_for_pending_acks_returns')->willReturnCallback(function (): void {
            $reject = $this->reject;
            self::assertNotNull($reject);
            $reject();
        });

        try {
            $this->worker->handle($this->delivery('{"message_id":10}'));
            self::fail('Expected publication rejection.');
        } catch (PublishException) {
        }

        self::assertSame([['publish', 'emails.retry', '{"message_id":10}']], $this->calls);
    }

    public function test_an_unconfirmed_publication_prevents_the_acknowledgement(): void {
        $this->givenMessage();
        $this->mailer->error = new TransientDeliveryException('SMTP 451');
        $this->channel->method('wait_for_pending_acks_returns')->willThrowException(new AMQPTimeoutException('timeout'));

        try {
            $this->worker->handle($this->delivery('{"message_id":10}'));
            self::fail('Expected publication failure.');
        } catch (PublishException) {
        }

        self::assertSame([['publish', 'emails.retry', '{"message_id":10}']], $this->calls);
    }

    public function test_a_persistence_failure_prevents_the_acknowledgement(): void {
        $this->givenMessage();
        $this->messages->saveError = new RuntimeException('database unavailable');

        try {
            $this->worker->handle($this->delivery('{"message_id":10}'));
            self::fail('Expected persistence failure.');
        } catch (RuntimeException) {
        }

        self::assertSame([], $this->calls);
        self::assertSame([], $this->mailer->recipients);
    }

    public function test_invalid_payloads_are_dead_lettered_without_calling_the_use_case(): void {
        $bodies = [
            'not json',
            'null',
            '[]',
            '{"message_id":"10"}',
            '{"message_id":0}',
            '{"message_id":-10}',
            '{"message_id":10.5}',
            '{"message_id":9223372036854775808}',
            '{"message_id":10,"recipient":"leak@exemplo.com"}',
        ];

        foreach ($bodies as $body) {
            $this->calls = [];
            $this->worker->handle($this->delivery($body));

            self::assertSame([
                ['publish', 'emails.dlq', '{"error":"invalid_payload"}'],
                ['ack', 1],
            ], $this->calls, $body);
            self::assertSame([], $this->mailer->recipients, $body);
            self::assertSame('worker_invalid_payload', $this->loggedEvent(), $body);
        }

        self::assertCount(count($bodies), $this->logs);
    }

    public function test_an_unknown_message_id_is_dead_lettered(): void {
        $this->worker->handle($this->delivery('{"message_id":999}'));

        self::assertSame([
            ['publish', 'emails.dlq', '{"message_id":999}'],
            ['ack', 1],
        ], $this->calls);
        self::assertSame('worker_message_not_found', $this->loggedEvent());
    }

    public function test_logs_do_not_include_body_recipient_or_credentials(): void {
        $this->givenMessage();

        $this->worker->handle($this->delivery('{"message_id":10}'));

        $log = $this->logs[0];
        self::assertStringNotContainsString(self::RECIPIENT, $log);
        self::assertStringNotContainsString('<p>', $log);
        self::assertStringNotContainsString('api-key', $log);
    }

    public function test_it_configures_prefetch_and_manual_consumption(): void {
        $this->channel->expects(self::once())->method('basic_qos')->with(0, 5, false);
        $this->channel->expects(self::once())->method('basic_consume')->with(
            'emails',
            '',
            false,
            false,
            false,
            false,
            self::isCallable(),
        );
        $this->channel->method('is_consuming')->willReturn(false);

        $this->worker->run();
    }

    private function givenMessage(bool $htmlPurged = false): void {
        $this->messages->messages[self::MESSAGE_ID] = new Message(
            id: self::MESSAGE_ID,
            batchId: 1,
            clientId: 1,
            recipient: self::RECIPIENT,
            createdAt: new DateTimeImmutable('2026-10-21T12:00:00+00:00'),
            status: MessageStatus::Queued,
        );
        $this->messages->batches[self::MESSAGE_ID] = new Batch(
            id: 1,
            clientId: 1,
            idempotencyKey: null,
            subject: 'Seu pedido saiu',
            html: '<p>Ola</p>',
            text: 'Ola',
            fromEmail: 'loja@exemplo.com',
            htmlPurgedAt: $htmlPurged ? new DateTimeImmutable('2026-10-20T00:00:00+00:00') : null,
        );
    }

    private function delivery(string $body): AMQPMessage {
        $delivery = new AMQPMessage($body);
        $delivery->setChannel($this->channel);
        $delivery->setDeliveryTag(1);

        return $delivery;
    }

    private function loggedEvent(): string {
        $log = json_decode($this->logs[count($this->logs) - 1], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($log);

        return (string) ($log['event'] ?? '');
    }
}
