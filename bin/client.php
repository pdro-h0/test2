<?php

declare(strict_types=1);

use App\Domain\Client;
use App\Infrastructure\Env;
use App\Infrastructure\Persistence\PdoClientRepository;

require dirname(__DIR__) . '/vendor/autoload.php';

$args = array_slice($argv, 1);
$name = $args[0] ?? '';
$fromEmail = $args[1] ?? '';
$fromName = $args[2] ?? null;
$rateLimit = $args[3] ?? '10';

if ($name === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "usage: php bin/client.php <name> <from_email> [from_name] [rate_limit_per_sec]\n");
    exit(1);
}

if (filter_var($rateLimit, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
    fwrite(STDERR, "rate_limit_per_sec must be a positive integer\n");
    exit(1);
}

$dsn = sprintf(
    'pgsql:host=%s;port=%s;dbname=%s',
    Env::get('POSTGRES_HOST'),
    Env::get('POSTGRES_PORT'),
    Env::get('POSTGRES_DB'),
);

$pdo = new PDO($dsn, Env::get('POSTGRES_USER'), Env::get('POSTGRES_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$apiKey = bin2hex(random_bytes(32));

$id = (new PdoClientRepository($pdo))->create(new Client(
    id: null,
    name: $name,
    apiKeyHash: hash('sha256', $apiKey),
    fromEmail: $fromEmail,
    fromName: $fromName,
    rateLimitPerSec: (int) $rateLimit,
    isActive: true,
));

fwrite(STDOUT, sprintf("client %d created\napi key (shown once, store it now): %s\n", $id, $apiKey));
