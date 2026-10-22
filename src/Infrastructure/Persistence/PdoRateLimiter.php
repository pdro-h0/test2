<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Port\RateLimiter;
use PDO;

final class PdoRateLimiter implements RateLimiter {
    public function __construct(private readonly PDO $pdo) {
    }

    public function allow(int $clientId, int $limitPerSec): bool {
        $statement = $this->pdo->prepare(
            'INSERT INTO rate_limits (client_id, window_start, hits)
             VALUES (:client_id, date_trunc(\'second\', now()), 1)
             ON CONFLICT (client_id) DO UPDATE SET
                 hits = CASE WHEN rate_limits.window_start = excluded.window_start
                             THEN rate_limits.hits + 1 ELSE 1 END,
                 window_start = excluded.window_start
             RETURNING hits'
        );
        $statement->bindValue(':client_id', $clientId, PDO::PARAM_INT);
        $statement->execute();

        return (int) $statement->fetchColumn() <= $limitPerSec;
    }
}
