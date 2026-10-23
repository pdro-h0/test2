<?php

declare(strict_types=1);

use App\Application\SendMessageUseCase;
use App\Infrastructure\Env;
use App\Infrastructure\Log\JsonLogger;
use App\Infrastructure\Mail\SmtpMailer;
use App\Infrastructure\Persistence\PdoMessageRepository;
use App\Infrastructure\Queue\RabbitMqPublisher;
use App\Infrastructure\Queue\RabbitMqWorker;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPExceptionInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

const ERROR_SLEEP_SECONDS = 1;

$postgresHost = Env::get('POSTGRES_HOST');
$postgresPort = Env::get('POSTGRES_PORT');
$postgresDb = Env::get('POSTGRES_DB');
$postgresUser = Env::get('POSTGRES_USER');
$postgresPassword = Env::get('POSTGRES_PASSWORD');
$amqpHost = Env::get('AMQP_HOST');
$amqpPort = Env::get('AMQP_PORT');
$amqpUser = Env::get('AMQP_USER');
$amqpPassword = Env::get('AMQP_PASSWORD');
$amqpVhost = Env::get('AMQP_VHOST');
$smtpHost = Env::get('SMTP_HOST');
$smtpPort = Env::get('SMTP_PORT');
$smtpEncryption = Env::get('SMTP_ENCRYPTION');
$smtpUser = getenv('SMTP_USER');
$smtpUser = $smtpUser === false || $smtpUser === '' ? null : $smtpUser;
$smtpPassword = $smtpUser === null ? null : Env::get('SMTP_PASSWORD');

foreach ([$postgresPort, $amqpPort, $smtpPort] as $port) {
    if (filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
        throw new InvalidArgumentException('Invalid service port');
    }
}

$mailer = new SmtpMailer($smtpHost, (int) $smtpPort, $smtpUser, $smtpPassword, $smtpEncryption);
$logger = JsonLogger::stdout();

while (true) {
    $connection = null;
    $consumeChannel = null;
    $publishChannel = null;

    try {
        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $postgresHost, $postgresPort, $postgresDb),
            $postgresUser,
            $postgresPassword,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $connection = new AMQPStreamConnection(
            $amqpHost,
            (int) $amqpPort,
            $amqpUser,
            $amqpPassword,
            $amqpVhost,
        );
        $consumeChannel = $connection->channel();
        $publishChannel = $connection->channel();
        $publisher = new RabbitMqPublisher($publishChannel);
        $sendMessage = new SendMessageUseCase(new PdoMessageRepository($pdo), $mailer);

        (new RabbitMqWorker($consumeChannel, $publisher, $sendMessage, $logger))->run();
    } catch (Throwable $error) {
        $logger->error('worker_error', new RuntimeException($error::class));
    } finally {
        foreach ([$consumeChannel, $publishChannel, $connection] as $resource) {
            if ($resource === null) {
                continue;
            }
            try {
                $resource->close();
            } catch (AMQPExceptionInterface $error) {
                $logger->error('worker_close_error', new RuntimeException($error::class));
            }
        }
    }

    sleep(ERROR_SLEEP_SECONDS);
}
