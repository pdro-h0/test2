<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Application\Exception\PublishException;

interface Publisher {
    /**
     * Publishes a persisted outbox message and waits for broker confirmation.
     *
     * @throws PublishException when delivery cannot be confirmed
     */
    public function publish(int $messageId): void;
}
