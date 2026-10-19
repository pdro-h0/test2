<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\MessageStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MessageStatusTest extends TestCase{
    #[DataProvider('transitions')]
    public function test_it_only_allows_the_documented_transitions(MessageStatus $from, MessageStatus $to, bool $allowed): void{
        self::assertSame($allowed, $from->canTransitionTo($to));
    }

    #[DataProvider('terminalStatuses')]
    public function test_it_knows_which_statuses_are_terminal(MessageStatus $status, bool $isTerminal): void{
        self::assertSame($isTerminal, $status->isTerminal());
    }

    /** @return array<string, array{MessageStatus, MessageStatus, bool}> */
    public static function transitions(): array{
        $allowed = [
            [MessageStatus::Queued, MessageStatus::Sending],
            [MessageStatus::Sending, MessageStatus::Sent],
            [MessageStatus::Sending, MessageStatus::Failed],
            [MessageStatus::Sending, MessageStatus::Queued],
        ];

        $cases = [];
        foreach (MessageStatus::cases() as $from) {
            foreach (MessageStatus::cases() as $to) {
                $cases[$from->value . ' -> ' . $to->value] = [$from, $to, in_array([$from, $to], $allowed, true)];
            }
        }

        return $cases;
    }

    /** @return array<string, array{MessageStatus, bool}> */
    public static function terminalStatuses(): array{
        return [
            'queued' => [MessageStatus::Queued, false],
            'sending' => [MessageStatus::Sending, false],
            'sent' => [MessageStatus::Sent, true],
            'failed' => [MessageStatus::Failed, true],
        ];
    }
}
