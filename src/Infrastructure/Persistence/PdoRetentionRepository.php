<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\BatchPayloadHash;
use App\Application\Port\RetentionRepository;
use DateTimeImmutable;
use PDO;
use Throwable;

final class PdoRetentionRepository implements RetentionRepository {
    public function __construct(private readonly PDO $pdo) {
    }

    public function fillMissingPayloadHashes(int $limit): int {
        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                'SELECT id, subject, html, text, from_email
                 FROM batches
                 WHERE idempotency_key IS NOT NULL
                   AND payload_hash IS NULL
                   AND html_purged_at IS NULL
                 ORDER BY id
                 LIMIT ?
                 FOR UPDATE SKIP LOCKED'
            );
            $statement->bindValue(1, $limit, PDO::PARAM_INT);
            $statement->execute();

            /** @var list<array{id: int|string, subject: string, html: string, text: string, from_email: string}> $batches */
            $batches = $statement->fetchAll(PDO::FETCH_ASSOC);

            $recipientsStatement = $this->pdo->prepare('SELECT recipient FROM messages WHERE batch_id = ? ORDER BY id');
            $update = $this->pdo->prepare('UPDATE batches SET payload_hash = ? WHERE id = ? AND payload_hash IS NULL');

            $filled = 0;

            foreach ($batches as $batch) {
                $batchId = (int) $batch['id'];
                $recipientsStatement->execute([$batchId]);

                /** @var list<string> $recipients */
                $recipients = $recipientsStatement->fetchAll(PDO::FETCH_COLUMN);

                $update->execute([
                    BatchPayloadHash::of($batch['subject'], $batch['html'], $batch['text'], $batch['from_email'], $recipients),
                    $batchId,
                ]);
                $filled += $update->rowCount();
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();

            throw $exception;
        }

        return $filled;
    }

    public function clearContent(DateTimeImmutable $createdBefore, DateTimeImmutable $purgedAt, int $limit): int {
        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                "SELECT id
                 FROM batches
                 WHERE html_purged_at IS NULL
                   AND (
                       created_at <= ?
                       OR (
                           EXISTS (SELECT 1 FROM messages WHERE messages.batch_id = batches.id)
                           AND NOT EXISTS (
                               SELECT 1 FROM messages
                               WHERE messages.batch_id = batches.id
                                 AND messages.status NOT IN ('sent', 'failed')
                           )
                       )
                   )
                   AND (idempotency_key IS NULL OR payload_hash IS NOT NULL)
                 ORDER BY id
                 LIMIT ?
                 FOR UPDATE SKIP LOCKED"
            );
            $statement->bindValue(1, $createdBefore->format(DATE_ATOM));
            $statement->bindValue(2, $limit, PDO::PARAM_INT);
            $statement->execute();

            /** @var list<int|string> $batchIds */
            $batchIds = $statement->fetchAll(PDO::FETCH_COLUMN);

            $update = $this->pdo->prepare(
                "UPDATE batches SET html = '', text = '', html_purged_at = ? WHERE id = ? AND html_purged_at IS NULL"
            );

            $purgedAtValue = $purgedAt->format(DATE_ATOM);
            $cleared = 0;

            foreach ($batchIds as $batchId) {
                $update->execute([$purgedAtValue, (int) $batchId]);
                $cleared += $update->rowCount();
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();

            throw $exception;
        }

        return $cleared;
    }

    public function deleteTerminalBatchesBefore(DateTimeImmutable $createdBefore, int $limit): int {
        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                "SELECT id
                 FROM batches
                 WHERE created_at <= ?
                   AND EXISTS (SELECT 1 FROM messages WHERE messages.batch_id = batches.id)
                   AND NOT EXISTS (
                       SELECT 1 FROM messages
                       WHERE messages.batch_id = batches.id
                         AND messages.status NOT IN ('sent', 'failed')
                   )
                 ORDER BY id
                 LIMIT ?
                 FOR UPDATE SKIP LOCKED"
            );
            $statement->bindValue(1, $createdBefore->format(DATE_ATOM));
            $statement->bindValue(2, $limit, PDO::PARAM_INT);
            $statement->execute();

            /** @var list<int|string> $batchIds */
            $batchIds = $statement->fetchAll(PDO::FETCH_COLUMN);

            $delete = $this->pdo->prepare('DELETE FROM batches WHERE id = ?');
            $deleted = 0;

            foreach ($batchIds as $batchId) {
                $delete->execute([(int) $batchId]);
                $deleted += $delete->rowCount();
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();

            throw $exception;
        }

        return $deleted;
    }
}
