<?php

declare(strict_types=1);

namespace App\Domain;

enum MessageStatus: string {
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';

    public function isTerminal(): bool {
        return $this === self::Sent || $this === self::Failed;
    }

    public function canTransitionTo(self $next): bool {
        return match ($this) {
            self::Queued => $next === self::Sending,
            self::Sending => $next === self::Sent || $next === self::Failed || $next === self::Queued,
            self::Sent, self::Failed => false,
        };
    }
}
