<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Port\BatchRepository;
use App\Application\Port\RateLimiter;
use App\Domain\Batch;
use App\Domain\Client;
use App\Domain\Exception\ConflictException;
use App\Domain\Exception\RateLimitException;
use App\Domain\Exception\ValidationException;
use LogicException;

final class CreateBatchUseCase {
    private const MAX_HTML_BYTES = 262144;
    private const MAX_SUBJECT_LENGTH = 255;
    private const MAX_EMAIL_LENGTH = 320;
    private const MAX_RECIPIENTS = 1000;
    private const MAX_IDEMPOTENCY_KEY_LENGTH = 128;

    public function __construct(
        private readonly BatchRepository $batches,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    /** @return array{batch_id: int, message_ids: list<int>, is_new: bool} */
    public function run(Client $client, CreateBatchInput $input): array {
        $clientId = $client->id ?? throw new LogicException('client must be persisted');

        if (!$this->rateLimiter->allow($clientId, $client->rateLimitPerSec)) {
            throw new RateLimitException('rate limit exceeded');
        }

        $subject = $this->subject($input->subject);
        $html = $this->html($input->html);
        $text = $this->text($input->text, $html);
        // shortcut: sender domain is only format-checked, check it against relay DKIM data before letting clients pick their own sender in production
        $fromEmail = $this->email($input->from ?? $client->fromEmail);
        $recipients = $this->recipients($input->recipients);
        $idempotencyKey = $this->idempotencyKey($input->idempotencyKey);
        $payloadHash = BatchPayloadHash::of($subject, $html, $text, $fromEmail, $recipients);

        $batch = new Batch(
            id: null,
            clientId: $clientId,
            idempotencyKey: $idempotencyKey,
            subject: $subject,
            html: $html,
            text: $text,
            fromEmail: $fromEmail,
        );

        if ($idempotencyKey !== null) {
            $existing = $this->batches->findByIdempotencyKey($clientId, $idempotencyKey);

            if ($existing !== null) {
                if (!$this->isSamePayload($existing, $payloadHash)) {
                    throw new ConflictException('idempotency key reused with a different payload');
                }

                return [
                    'batch_id' => $existing['batch_id'],
                    'message_ids' => $existing['message_ids'],
                    'is_new' => false,
                ];
            }
        }

        $saved = $this->batches->save($batch, $recipients, $payloadHash);

        // shortcut: a concurrent request with the same key races past the lookup and dies on the unique constraint, catch SQLSTATE 23505 and re-read when duplicate retries show up
        return ['batch_id' => $saved['batch_id'], 'message_ids' => $saved['message_ids'], 'is_new' => true];
    }

    private function subject(string $subject): string {
        if ($subject === '') {
            throw new ValidationException('subject is required');
        }

        if (mb_strlen($subject) > self::MAX_SUBJECT_LENGTH) {
            throw new ValidationException('subject is too long');
        }

        return $subject;
    }

    private function html(string $html): string {
        if ($html === '') {
            throw new ValidationException('html is required');
        }

        if (strlen($html) > self::MAX_HTML_BYTES) {
            throw new ValidationException('html is too large');
        }

        return $html;
    }

    private function text(?string $text, string $html): string {
        return $text === null || $text === '' ? strip_tags($html) : $text;
    }

    private function email(string $email): string {
        if (strlen($email) > self::MAX_EMAIL_LENGTH || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException('invalid email address');
        }

        return $email;
    }

    /**
     * @param array<array-key, mixed> $recipients
     * @return list<string>
     */
    private function recipients(array $recipients): array {
        if ($recipients === []) {
            throw new ValidationException('recipients are required');
        }

        if (count($recipients) > self::MAX_RECIPIENTS) {
            throw new ValidationException('too many recipients');
        }

        $validated = [];
        $seen = [];

        foreach ($recipients as $recipient) {
            if (!is_string($recipient)) {
                throw new ValidationException('invalid email address');
            }

            $this->email($recipient);

            if (isset($seen[$recipient])) continue;

            $seen[$recipient] = true;
            $validated[] = $recipient;
        }

        return $validated;
    }

    private function idempotencyKey(?string $idempotencyKey): ?string {
        if ($idempotencyKey === null) {
            return null;
        }

        if ($idempotencyKey === '' || strlen($idempotencyKey) > self::MAX_IDEMPOTENCY_KEY_LENGTH) {
            throw new ValidationException('invalid idempotency key');
        }

        return $idempotencyKey;
    }

    /**
     * @param array{
     *     batch_id: int,
     *     subject: string,
     *     html: string,
     *     text: string,
     *     from_email: string,
     *     payload_hash: ?string,
     *     html_purged_at: ?string,
     *     recipients: list<string>,
     *     message_ids: list<int>,
     * } $existing
     */
    private function isSamePayload(array $existing, string $payloadHash): bool {
        if ($existing['payload_hash'] !== null) {
            return $existing['payload_hash'] === $payloadHash;
        }

        if ($existing['html_purged_at'] !== null) {
            return false;
        }

        return BatchPayloadHash::of(
            $existing['subject'],
            $existing['html'],
            $existing['text'],
            $existing['from_email'],
            $existing['recipients'],
        ) === $payloadHash;
    }
}
