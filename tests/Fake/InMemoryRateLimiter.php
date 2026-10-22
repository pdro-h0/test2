<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Application\Port\RateLimiter;

final class InMemoryRateLimiter implements RateLimiter {
    public bool $allowed = true;

    public function allow(int $clientId, int $limitPerSec): bool {
        return $this->allowed;
    }
}
