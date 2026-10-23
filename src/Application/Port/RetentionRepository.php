<?php

declare(strict_types=1);

namespace App\Application\Port;

use DateTimeImmutable;

interface RetentionRepository {
    /**
     * Fills missing payload hashes for old batches in one limited group.
     *
     * @return int number of batches that received a hash
     */
    public function fillMissingPayloadHashes(int $limit): int;

    /**
     * Clears html and text of terminal or old batches in one limited group.
     *
     * @return int number of batches that were cleared
     */
    public function clearContent(DateTimeImmutable $createdBefore, DateTimeImmutable $purgedAt, int $limit): int;

    /**
     * Deletes old terminal batches, cascading to messages and outbox, in one limited group.
     *
     * @return int number of batches that were deleted
     */
    public function deleteTerminalBatchesBefore(DateTimeImmutable $createdBefore, int $limit): int;
}
