<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Application\PurgeRetentionUseCase;
use App\Tests\Fake\InMemoryRetentionRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PurgeRetentionUseCaseTest extends TestCase {
    private InMemoryRetentionRepository $retention;
    private PurgeRetentionUseCase $sut;
    private DateTimeImmutable $now;

    protected function setUp(): void {
        $this->retention = new InMemoryRetentionRepository();
        $this->sut = new PurgeRetentionUseCase($this->retention);
        $this->now = new DateTimeImmutable('2026-10-23T12:00:00+00:00');
    }

    public function test_it_uses_90_and_30_day_limits_from_the_given_reference(): void {
        $this->retention->terminalBatches = 1;
        $this->retention->missingHashes = 1;
        $this->retention->unpurgedBatches = 1;

        $this->sut->run($this->now);

        self::assertSame('2026-07-25T12:00:00+00:00', $this->retention->deletions[0]['created_before']->format(DATE_ATOM));
        self::assertSame(100, $this->retention->deletions[0]['limit']);
        self::assertSame(100, $this->retention->hashLimits[0]);
        self::assertSame('2026-09-23T12:00:00+00:00', $this->retention->clearings[0]['created_before']->format(DATE_ATOM));
        self::assertSame('2026-10-23T12:00:00+00:00', $this->retention->clearings[0]['purged_at']->format(DATE_ATOM));
        self::assertSame(100, $this->retention->clearings[0]['limit']);
    }

    public function test_it_runs_delete_then_hash_then_clear(): void {
        $this->sut->run($this->now);

        self::assertSame(['delete', 'hash', 'clear'], $this->retention->calls);
    }

    public function test_it_returns_zero_totals_when_there_are_no_candidates(): void {
        self::assertSame(
            ['deleted_batches' => 0, 'hashed_batches' => 0, 'cleared_batches' => 0],
            $this->sut->run($this->now),
        );
    }

    public function test_it_repeats_each_step_until_empty_and_sums_the_totals(): void {
        $this->retention->terminalBatches = 250;
        $this->retention->missingHashes = 150;
        $this->retention->unpurgedBatches = 100;

        $totals = $this->sut->run($this->now);

        self::assertSame(
            ['deleted_batches' => 250, 'hashed_batches' => 150, 'cleared_batches' => 100],
            $totals,
        );
        self::assertSame(
            ['delete', 'delete', 'delete', 'delete', 'hash', 'hash', 'hash', 'clear', 'clear'],
            $this->retention->calls,
        );
    }

    public function test_a_failure_in_the_delete_step_does_not_start_the_next_steps(): void {
        $error = new RuntimeException('boom');
        $this->retention->errors['delete'] = $error;

        try {
            $this->sut->run($this->now);
            self::fail('Expected the failure to propagate.');
        } catch (RuntimeException $caught) {
            self::assertSame($error, $caught);
        }

        self::assertSame(['delete'], $this->retention->calls);
    }

    public function test_a_failure_in_the_hash_step_does_not_start_the_cleanup(): void {
        $this->retention->errors['hash'] = new RuntimeException('boom');

        try {
            $this->sut->run($this->now);
            self::fail('Expected the failure to propagate.');
        } catch (RuntimeException) {
        }

        self::assertSame(['delete', 'hash'], $this->retention->calls);
    }
}
