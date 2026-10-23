<?php

declare(strict_types=1);

use App\Application\PurgeRetentionUseCase;
use App\Infrastructure\Env;
use App\Infrastructure\Log\JsonLogger;
use App\Infrastructure\Persistence\PdoRetentionRepository;

require dirname(__DIR__) . '/vendor/autoload.php';

$logger = JsonLogger::stdout();

try {
    $logger->info('purge_started');

    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        Env::get('POSTGRES_HOST'),
        Env::get('POSTGRES_PORT'),
        Env::get('POSTGRES_DB'),
    );

    $pdo = new PDO($dsn, Env::get('POSTGRES_USER'), Env::get('POSTGRES_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $totals = (new PurgeRetentionUseCase(new PdoRetentionRepository($pdo)))->run(
        new DateTimeImmutable('now', new DateTimeZone('UTC')),
    );

    $logger->info('purge_completed', $totals);
} catch (Throwable $error) {
    $logger->error('purge_failed', new RuntimeException($error::class));
    exit(1);
}
