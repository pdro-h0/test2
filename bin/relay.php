<?php

declare(strict_types=1);

use App\Infrastructure\Env;
use App\Infrastructure\Log\JsonLogger;
use App\Infrastructure\Persistence\PdoOutboxRepository;
use App\Infrastructure\Queue\RabbitMqPublisher;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPExceptionInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

const IDLE_SLEEP_MICROSECONDS = 200000;
const ERROR_SLEEP_SECONDS = 1;

$postgresHost = Env::get('POSTGRES_HOST');
$postgresPort = Env::get('POSTGRES_PORT');
$postgresDb = Env::get('POSTGRES_DB');
$postgresUser = Env::get('POSTGRES_USER');
$postgresPassword = Env::get('POSTGRES_PASSWORD');
$amqpHost = Env::get('AMQP_HOST');
$amqpPort = (int) Env::get('AMQP_PORT');
$amqpUser = Env::get('AMQP_USER');
$amqpPassword = Env::get('AMQP_PASSWORD');
$amqpVhost = Env::get('AMQP_VHOST');

$connectPdo = static fn (): PDO => new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s', $postgresHost, $postgresPort, $postgresDb),
    $postgresUser,
    $postgresPassword,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$connectAmqp = static fn (): AMQPStreamConnection => new AMQPStreamConnection(
    $amqpHost,
    $amqpPort,
    $amqpUser,
    $amqpPassword,
    $amqpVhost,
);

$logger = JsonLogger::stdout();
$pdo = null;
$connection = null;
$channel = null;
$publisher = null;

while (true) {
    try {
        if (!$pdo instanceof PDO) {
            $pdo = $connectPdo();
        }

        if (!$publisher instanceof RabbitMqPublisher) {
            $connection = $connectAmqp();
            $channel = $connection->channel();
            $publisher = new RabbitMqPublisher($channel);
        }

        $published = (new PdoOutboxRepository($pdo))->publishNext($publisher);

        if ($published === null) {
            usleep(IDLE_SLEEP_MICROSECONDS);
            continue;
        }

        $logger->info('outbox_published', ['message_id' => $published['message_id']]);
    } catch (Throwable $error) {
        $logger->error('relay_error', $error);

        try {
            if ($channel instanceof AMQPChannel && $channel->is_open()) {
                $channel->close();
            }
            if ($connection instanceof AMQPStreamConnection && $connection->isConnected()) {
                $connection->close();
            }
        } catch (AMQPExceptionInterface) {
            // The connection is already gone, nothing left to close.
        }

        $pdo = null;
        $connection = null;
        $channel = null;
        $publisher = null;
        sleep(ERROR_SLEEP_SECONDS);
    }
}
