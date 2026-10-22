<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mail;

use App\Infrastructure\Mail\SmtpError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SmtpErrorTest extends TestCase {
    #[DataProvider('smtpCodes')]
    public function test_it_treats_only_5xx_as_permanent(?string $smtpCode, bool $isPermanent): void {
        self::assertSame($isPermanent, SmtpError::isPermanent($smtpCode));
    }

    /** @return array<string, array{?string, bool}> */
    public static function smtpCodes(): array {
        return [
            'rejected with 550' => ['550', true],
            'rejected with 554' => ['554', true],
            'try again later 421' => ['421', false],
            'mailbox busy 450' => ['450', false],
            'timeout without code' => [null, false],
            'empty code' => ['', false],
            'not a number' => ['abc', false],
        ];
    }
}
