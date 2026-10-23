<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Port\Mailer;
use App\Application\Port\MessageRepository;
use App\Application\SendMessageUseCase;
use App\Domain\Batch;
use App\Domain\Client;
use App\Domain\Exception\PermanentDeliveryException;
use App\Domain\Exception\TransientDeliveryException;
use App\Infrastructure\Log\JsonLogger;
use App\Infrastructure\Mail\SmtpMailer;
use App\Infrastructure\Persistence\MigrationRunner;
use App\Infrastructure\Persistence\PdoBatchRepository;
use App\Infrastructure\Persistence\PdoClientRepository;
use App\Infrastructure\Persistence\PdoMessageRepository;
use App\Infrastructure\Persistence\PdoOutboxRepository;
use App\Infrastructure\Queue\RabbitMqPublisher;
use App\Infrastructure\Queue\RabbitMqWorker;
use App\Tests\Fake\FailingMessageRepository;
use App\Tests\Fake\RecordingMailer;
use PDO;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RabbitMqWorkerTest extends TestCase {
    private const RETRY_WAIT_SECONDS = 35;
    private const POLL_MICROSECONDS = 100000;
    private const MAILPIT_API_PORT = 8025;

    private PDO $pdo;
    private string $schema;
    private AMQPStreamConnection $connection;
    private AMQPChannel $consumeChannel;
    private AMQPChannel $publishChannel;
    private RabbitMqPublisher $publisher;
    private int $clientId;

    protected function setUp(): void {
        $this->pdo = new PDO(
            sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                self::env('POSTGRES_HOST'),
                self::env('POSTGRES_PORT'),
                self::env('POSTGRES_DB'),
            ),
            self::env('POSTGRES_USER'),
            self::env('POSTGRES_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $this->schema = 'worker_test_' . bin2hex(random_bytes(4));
        $this->pdo->exec('CREATE SCHEMA ' . $this->schema);
        $this->pdo->exec('SET search_path TO ' . $this->schema);

        (new MigrationRunner($this->pdo, __DIR__ . '/../../migrations'))->run();

        $this->clientId = (new PdoClientRepository($this->pdo))->create(new Client(
            id: null,
            name: 'Loja',
            apiKeyHash: hash('sha256', 'chave-secreta'),
            fromEmail: 'loja@exemplo.com',
            fromName: 'Loja',
            rateLimitPerSec: 10,
            isActive: true,
        ));

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
        $this->consumeChannel = $this->connection->channel();
        $this->publishChannel = $this->connection->channel();
        $this->publisher = new RabbitMqPublisher($this->publishChannel);

        foreach (['emails', 'emails.retry', 'emails.dlq'] as $queue) {
            $this->publishChannel->queue_purge($queue);
        }
    }

    protected function tearDown(): void {
        if (isset($this->connection) && $this->connection->isConnected()) {
            $this->connection->close();
        }

        if (isset($this->schema)) {
            $this->pdo->exec('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
    }

    public function test_it_sends_through_mailpit_and_acknowledges_the_delivery(): void {
        $host = $this->requireMailpit();
        $subject = 'worker success ' . bin2hex(random_bytes(4));
        $messageIds = $this->createMessages($subject, ['success@exemplo.com']);
        $delivery = $this->publishFromOutbox($messageIds[0]);

        $this->worker(new SmtpMailer($host, (int) self::env('SMTP_PORT'), null, null, 'none'))->handle($delivery);

        self::assertSame('sent', $this->scalar('SELECT status FROM messages WHERE id = ?', [$messageIds[0]]));
        self::assertSame(0, (int) $this->scalar('SELECT attempts FROM messages WHERE id = ?', [$messageIds[0]]));
        self::assertNotNull($this->scalar('SELECT sent_at FROM messages WHERE id = ?', [$messageIds[0]]));
        self::assertSame(0, $this->queueCount('emails'));

        $messages = $this->mailpitMessages();
        self::assertCount(1, $messages);
        self::assertSame($subject, $messages[0]['Subject']);
        self::assertSame(['success@exemplo.com'], array_column($messages[0]['To'], 'Address'));
    }

    public function test_a_transient_failure_is_requeued_and_returns_after_the_fixed_ttl(): void {
        $messageIds = $this->createMessages('worker retry', ['retry@exemplo.com']);
        $delivery = $this->publishFromOutbox($messageIds[0]);
        $mailer = new RecordingMailer();
        $mailer->error = new TransientDeliveryException('SMTP 451');

        $this->worker($mailer)->handle($delivery);

        self::assertSame('queued', $this->scalar('SELECT status FROM messages WHERE id = ?', [$messageIds[0]]));
        self::assertSame(1, (int) $this->scalar('SELECT attempts FROM messages WHERE id = ?', [$messageIds[0]]));
        self::assertSame('SMTP 451', $this->scalar('SELECT last_error FROM messages WHERE id = ?', [$messageIds[0]]));
        self::assertSame(0, $this->queueCount('emails'));
        self::assertSame(1, $this->queueCount('emails.retry'));

        $deadline = microtime(true) + self::RETRY_WAIT_SECONDS;
        do {
            $returned = $this->takeFrom('emails');
            if ($returned instanceof AMQPMessage) {
                self::assertSame(
                    json_encode(['message_id' => $messageIds[0]], JSON_THROW_ON_ERROR),
                    $returned->getBody(),
                );
                $returned->ack();

                return;
            }
            usleep(self::POLL_MICROSECONDS);
        } while (microtime(true) < $deadline);

        self::fail('Retry message did not return to emails before the deadline.');
    }

    public function test_the_fifth_failure_marks_failed_and_dead_letters(): void {
        $messageIds = $this->createMessages('worker fifth failure', ['fifth@exemplo.com']);
        $this->pdo->prepare('UPDATE messages SET attempts = 4 WHERE id = ?')->execute([$messageIds[0]]);
        $delivery = $this->publishFromOutbox($messageIds[0]);
        $mailer = new RecordingMailer();
        $mailer->error = new TransientDeliveryException('SMTP 451');

        $this->worker($mailer)->handle($delivery);

        self::assertSame('failed', $this->scalar('SELECT status FROM messages WHERE id = ?', [$messageIds[0]]));
        self::assertSame(5, (int) $this->scalar('SELECT attempts FROM messages WHERE id = ?', [$messageIds[0]]));
        self::assertSame(0, $this->queueCount('emails.retry'));
        $this->assertDeadLettered($messageIds[0]);
    }

    public function test_a_permanent_failure_marks_failed_and_dead_letters_without_retry(): void {
        $messageIds = $this->createMessages('worker permanent failure', ['permanent@exemplo.com']);
        $delivery = $this->publishFromOutbox($messageIds[0]);
        $mailer = new RecordingMailer();
        $mailer->error = new PermanentDeliveryException('SMTP 550');

        $this->worker($mailer)->handle($delivery);

        self::assertSame('failed', $this->scalar('SELECT status FROM messages WHERE id = ?', [$messageIds[0]]));
        self::assertSame(1, (int) $this->scalar('SELECT attempts FROM messages WHERE id = ?', [$messageIds[0]]));
        self::assertSame(0, $this->queueCount('emails.retry'));
        $this->assertDeadLettered($messageIds[0]);
    }

    public function test_a_purged_content_message_is_dead_lettered_without_sending(): void {
        $messageIds = $this->createMessages('worker purged content', ['purged@exemplo.com']);
        $this->pdo
            ->prepare("UPDATE batches SET html = '', text = '', html_purged_at = now()
                       WHERE id = (SELECT batch_id FROM messages WHERE id = ?)")
            ->execute([$messageIds[0]]);
        $delivery = $this->publishFromOutbox($messageIds[0]);
        $mailer = new RecordingMailer();

        $this->worker($mailer)->handle($delivery);

        self::assertSame('failed', $this->scalar('SELECT status FROM messages WHERE id = ?', [$messageIds[0]]));
        self::assertSame('message content expired', $this->scalar('SELECT last_error FROM messages WHERE id = ?', [$messageIds[0]]));
        self::assertSame([], $mailer->recipients);
        $this->assertDeadLettered($messageIds[0]);
    }

    public function test_a_redelivered_sent_message_is_not_sent_again(): void {
        $messageIds = $this->createMessages('worker redelivery', ['redelivery@exemplo.com']);
        $mailer = new RecordingMailer();
        $worker = $this->worker($mailer);
        $worker->handle($this->publishFromOutbox($messageIds[0]));

        $this->publisher->publish($messageIds[0]);
        $duplicate = $this->takeFrom('emails');
        self::assertInstanceOf(AMQPMessage::class, $duplicate);
        $worker->handle($duplicate);

        self::assertSame(['redelivery@exemplo.com'], $mailer->recipients);
        self::assertSame('sent', $this->scalar('SELECT status FROM messages WHERE id = ?', [$messageIds[0]]));
        self::assertSame(0, $this->queueCount('emails'));
    }

    public function test_a_failure_before_ack_leaves_the_delivery_available_for_redelivery(): void {
        $messageIds = $this->createMessages('worker no ack', ['no-ack@exemplo.com']);
        $worker = $this->worker(
            new RecordingMailer(),
            new FailingMessageRepository(new PdoMessageRepository($this->pdo)),
        );
        $delivery = $this->publishFromOutbox($messageIds[0]);

        try {
            $worker->handle($delivery);
            self::fail('Expected persistence failure.');
        } catch (RuntimeException) {
        }

        $this->consumeChannel->close();
        $this->publishChannel->close();
        $this->connection->close();

        $connection = new AMQPStreamConnection(
            self::env('AMQP_HOST'),
            (int) self::env('AMQP_PORT'),
            self::env('AMQP_USER'),
            self::env('AMQP_PASSWORD'),
            self::env('AMQP_TEST_VHOST'),
        );
        $channel = $connection->channel();
        $redelivered = $channel->basic_get('emails');

        self::assertInstanceOf(AMQPMessage::class, $redelivered);
        self::assertSame(true, $redelivered->isRedelivered());
        self::assertSame(
            json_encode(['message_id' => $messageIds[0]], JSON_THROW_ON_ERROR),
            $redelivered->getBody(),
        );
        $redelivered->ack();
        $channel->close();
        $connection->close();
    }

    public function test_an_unknown_message_id_is_dead_lettered_without_sending(): void {
        $this->publisher->publish(999999);
        $delivery = $this->takeFrom('emails');
        self::assertInstanceOf(AMQPMessage::class, $delivery);
        $mailer = new RecordingMailer();

        $this->worker($mailer)->handle($delivery);

        self::assertSame([], $mailer->recipients);
        $this->assertDeadLettered(999999);
    }

    /**
     * @param list<string> $recipients
     * @return list<int>
     */
    private function createMessages(string $subject, array $recipients): array {
        return (new PdoBatchRepository($this->pdo))->save(
            new Batch(
                id: null,
                clientId: $this->clientId,
                idempotencyKey: null,
                subject: $subject,
                html: '<p>Olá</p>',
                text: 'Olá',
                fromEmail: 'loja@exemplo.com',
            ),
            $recipients,
            str_repeat('a', 64),
        )['message_ids'];
    }

    private function publishFromOutbox(int $messageId): AMQPMessage {
        $published = (new PdoOutboxRepository($this->pdo))->publishNext($this->publisher);
        self::assertSame($messageId, $published['message_id'] ?? null);
        $delivery = $this->takeFrom('emails');
        self::assertInstanceOf(AMQPMessage::class, $delivery);

        return $delivery;
    }

    private function worker(Mailer $mailer, ?MessageRepository $repository = null): RabbitMqWorker {
        return new RabbitMqWorker(
            $this->consumeChannel,
            $this->publisher,
            new SendMessageUseCase($repository ?? new PdoMessageRepository($this->pdo), $mailer),
            new JsonLogger(static function (string $line): void {
            }),
        );
    }

    private function takeFrom(string $queue): ?AMQPMessage {
        $message = $this->consumeChannel->basic_get($queue);

        return $message instanceof AMQPMessage ? $message : null;
    }

    private function assertDeadLettered(int $messageId): void {
        $deadLetter = $this->takeFrom('emails.dlq');
        self::assertInstanceOf(AMQPMessage::class, $deadLetter);
        self::assertSame(json_encode(['message_id' => $messageId], JSON_THROW_ON_ERROR), $deadLetter->getBody());
        $deadLetter->ack();
    }

    private function queueCount(string $queue): int {
        $declared = $this->publishChannel->queue_declare($queue, true) ?? [];

        return (int) ($declared[1] ?? 0);
    }

    /** @param list<int|string|null> $params */
    private function scalar(string $sql, array $params): int|string|null {
        $statement = $this->pdo->prepare($sql);
        self::assertNotFalse($statement);
        $statement->execute($params);

        $value = $statement->fetchColumn();
        self::assertNotFalse($value);

        return $value;
    }

    private function requireMailpit(): string {
        $host = self::env('SMTP_HOST');

        if (!in_array($host, ['127.0.0.1', 'localhost'], true)) {
            self::markTestSkipped('SMTP_HOST is not local; tests only use Mailpit.');
        }

        $this->mailpitRequest('DELETE', '/api/v1/messages');

        return $host;
    }

    /** @return list<array{Subject: string, To: list<array{Address: string}>}> */
    private function mailpitMessages(): array {
        /** @var array{messages: list<array{Subject: string, To: list<array{Address: string}>}>} $list */
        $list = json_decode($this->mailpitRequest('GET', '/api/v1/messages'), true, 512, JSON_THROW_ON_ERROR);

        return $list['messages'];
    }

    /** @param non-empty-string $method */
    private function mailpitRequest(string $method, string $path): string {
        $handle = curl_init(sprintf('http://%s:%d%s', self::env('SMTP_HOST'), self::MAILPIT_API_PORT, $path));
        self::assertNotFalse($handle);
        curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_TIMEOUT, 5);

        $body = curl_exec($handle);
        self::assertIsString($body);

        return $body;
    }

    private static function env(string $key): string {
        $value = getenv($key);

        if ($value === false || $value === '') {
            self::markTestSkipped(sprintf(
                'Integration services not configured (%s missing). Run: docker compose up -d && set -a; . ./.env; set +a',
                $key,
            ));
        }

        return $value;
    }
}
