<?php

declare(strict_types=1);

namespace App\Application;

final class BatchPayloadHash {
    /** @param list<string> $recipients */
    public static function of(
        string $subject,
        string $html,
        string $text,
        string $fromEmail,
        array $recipients,
    ): string {
        $payload = '';

        foreach ([$subject, $html, $text, $fromEmail, ...$recipients] as $value) {
            $payload .= strlen($value) . ':' . $value;
        }

        return hash('sha256', $payload);
    }
}
