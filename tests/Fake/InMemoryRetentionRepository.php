<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Application\Port\RetentionRepository;
use DateTimeImmutable;
use Throwable;

final class InMemoryRetentionRepository implements RetentionRepository {
    /** @var list<string> */
    public array $calls = [];

    /** @var list<array{created_before: DateTimeImmutable, limit: int}> */
    public array $deletions = [];

    /** @var list<int> */
    public array $hashLimits = [];

    /** @var list<array{created_before: DateTimeImmutable, purged_at: DateTimeImmutable, limit: int}> */
    public array $clearings = [];

    /** @var array<string, Throwable> */
    public array $errors = [];

    public int $terminalBatches = 0;
    public int $missingHashes = 0;
    public int $unpurgedBatches = 0;

    public function deleteTerminalBatchesBefore(DateTimeImmutable $createdBefore, int $limit): int {
        $this->calls[] = 'delete';
        $this->deletions[] = ['created_before' => $createdBefore, 'limit' => $limit];
        $this->fail('delete');

        $count = min($this->terminalBatches, $limit);
        $this->terminalBatches -= $count;

        return $count;
    }

    public function fillMissingPayloadHashes(int $limit): int {
        $this->calls[] = 'hash';
        $this->hashLimits[] = $limit;
        $this->fail('hash');

        $count = min($this->missingHashes, $limit);
        $this->missingHashes -= $count;

        return $count;
    }

    public function clearContent(DateTimeImmutable $createdBefore, DateTimeImmutable $purgedAt, int $limit): int {
        $this->calls[] = 'clear';
        $this->clearings[] = [
            'created_before' => $createdBefore,
            'purged_at' => $purgedAt,
            'limit' => $limit,
        ];
        $this->fail('clear');

        $count = min($this->unpurgedBatches, $limit);
        $this->unpurgedBatches -= $count;

        return $count;
    }

    private function fail(string $operation): void {
        if (isset($this->errors[$operation])) {
            throw $this->errors[$operation];
        }
    }
}
