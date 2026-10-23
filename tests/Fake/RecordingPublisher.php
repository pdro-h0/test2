<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Application\Port\Publisher;
use Throwable;

final class RecordingPublisher implements Publisher {
    /** @var list<int> */
    public array $published = [];

    public ?Throwable $error = null;

    public function publish(int $messageId): void {
        if ($this->error !== null) {
            throw $this->error;
        }

        $this->published[] = $messageId;
    }
}
