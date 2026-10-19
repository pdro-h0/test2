<?php

declare(strict_types=1);

use App\Infrastructure\Env;
use App\Infrastructure\Persistence\MigrationRunner;

require dirname(__DIR__) . '/vendor/autoload.php';

$dsn = sprintf(
    'pgsql:host=%s;port=%s;dbname=%s',
    Env::get('POSTGRES_HOST'),
    Env::get('POSTGRES_PORT'),
    Env::get('POSTGRES_DB'),
);

$pdo = new PDO($dsn, Env::get('POSTGRES_USER'), Env::get('POSTGRES_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

try {
    $applied = (new MigrationRunner($pdo, dirname(__DIR__) . '/migrations'))->run();
} catch (Throwable $e) {
    fwrite(STDERR, sprintf("migrate failed: %s\n", $e->getMessage()));
    exit(1);
}

if ($applied === []) {
    fwrite(STDOUT, "nothing to apply\n");
    exit(0);
}

foreach ($applied as $name) {
    fwrite(STDOUT, sprintf("applied %s\n", $name));
}
