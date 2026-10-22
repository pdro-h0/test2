<?php

declare(strict_types=1);

namespace App\Application\Port;

interface RateLimiter {
    public function allow(int $clientId, int $limitPerSec): bool;
}
