<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail;

final class SmtpError {
    public static function isPermanent(?string $smtpCode): bool {
        if ($smtpCode === null || !ctype_digit($smtpCode)) {
            return false;
        }

        $code = (int) $smtpCode;

        return $code >= 500 && $code < 600;
    }
}
