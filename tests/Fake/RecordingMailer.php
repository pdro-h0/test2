<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Application\Port\Mailer;
use App\Domain\Batch;
use Throwable;

final class RecordingMailer implements Mailer {
    /** @var list<string> */
    public array $recipients = [];

    public ?Throwable $error = null;

    public function send(Batch $batch, string $recipient): void {
        if ($this->error !== null) {
            throw $this->error;
        }

        $this->recipients[] = $recipient;
    }
}
