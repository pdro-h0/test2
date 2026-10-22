<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Batch;
use App\Domain\Exception\PermanentDeliveryException;
use App\Domain\Exception\TransientDeliveryException;

interface Mailer {
    /**
     * Sends one message to exactly one recipient, never to a list.
     *
     * @throws PermanentDeliveryException on a 5xx reply; do not retry
     * @throws TransientDeliveryException on 4xx, timeout or connection failure
     */
    public function send(Batch $batch, string $recipient): void;
}
