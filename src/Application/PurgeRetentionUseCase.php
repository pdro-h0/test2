<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Port\RetentionRepository;
use DateTimeImmutable;

final class PurgeRetentionUseCase {
    private const CONTENT_RETENTION_DAYS = 30;
    private const METADATA_RETENTION_DAYS = 90;
    private const GROUP_SIZE = 100;

    public function __construct(private readonly RetentionRepository $retention) {
    }

    /**
     * @return array{deleted_batches: int, hashed_batches: int, cleared_batches: int}
     */
    public function run(DateTimeImmutable $now): array {
        $metadataBefore = $now->modify(sprintf('-%d days', self::METADATA_RETENTION_DAYS));
        $contentBefore = $now->modify(sprintf('-%d days', self::CONTENT_RETENTION_DAYS));

        // A group locked by another run is skipped by the repository and left for the next run.
        $deleted = $this->repeat(
            fn(): int => $this->retention->deleteTerminalBatchesBefore($metadataBefore, self::GROUP_SIZE)
        );
        $hashed = $this->repeat(
            fn(): int => $this->retention->fillMissingPayloadHashes(self::GROUP_SIZE)
        );
        $cleared = $this->repeat(
            fn(): int => $this->retention->clearContent($contentBefore, $now, self::GROUP_SIZE)
        );

        return [
            'deleted_batches' => $deleted,
            'hashed_batches' => $hashed,
            'cleared_batches' => $cleared,
        ];
    }

    /** @param callable(): int $operation */
    private function repeat(callable $operation): int {
        $total = 0;

        do {
            $count = $operation();
            $total += $count;
        } while ($count > 0);

        return $total;
    }
}
