<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Application\BatchPayloadHash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BatchPayloadHashTest extends TestCase {
    private const RECIPIENTS = ['a@exemplo.com', 'b@exemplo.com'];

    public function test_it_is_stable_for_the_same_normalized_payload(): void {
        self::assertSame(
            BatchPayloadHash::of('Assunto', '<p>Olá</p>', 'Olá', 'loja@exemplo.com', self::RECIPIENTS),
            BatchPayloadHash::of('Assunto', '<p>Olá</p>', 'Olá', 'loja@exemplo.com', self::RECIPIENTS),
        );
    }

    public function test_field_boundaries_cannot_be_shifted(): void {
        self::assertNotSame(
            BatchPayloadHash::of('ab', 'c', '', '', []),
            BatchPayloadHash::of('a', 'bc', '', '', []),
        );
    }

    /** @param list<string> $recipients */
    #[DataProvider('changedFields')]
    public function test_it_changes_when_any_field_changes(
        string $subject,
        string $html,
        string $text,
        string $fromEmail,
        array $recipients,
    ): void {
        self::assertNotSame(
            BatchPayloadHash::of('Assunto', '<p>Olá</p>', 'Olá', 'loja@exemplo.com', self::RECIPIENTS),
            BatchPayloadHash::of($subject, $html, $text, $fromEmail, $recipients),
        );
    }

    /** @return array<string, array{string, string, string, string, list<string>}> */
    public static function changedFields(): array {
        return [
            'subject' => ['Outro', '<p>Olá</p>', 'Olá', 'loja@exemplo.com', self::RECIPIENTS],
            'html' => ['Assunto', '<p>Oi</p>', 'Olá', 'loja@exemplo.com', self::RECIPIENTS],
            'text' => ['Assunto', '<p>Olá</p>', 'Oi', 'loja@exemplo.com', self::RECIPIENTS],
            'from' => ['Assunto', '<p>Olá</p>', 'Olá', 'outro@exemplo.com', self::RECIPIENTS],
            'recipient order' => ['Assunto', '<p>Olá</p>', 'Olá', 'loja@exemplo.com', ['b@exemplo.com', 'a@exemplo.com']],
            'extra recipient' => ['Assunto', '<p>Olá</p>', 'Olá', 'loja@exemplo.com', ['a@exemplo.com', 'b@exemplo.com', 'c@exemplo.com']],
        ];
    }
}
