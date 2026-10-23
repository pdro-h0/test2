<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Application\BatchPayloadHash;
use App\Application\CreateBatchInput;
use App\Application\CreateBatchUseCase;
use App\Domain\Client;
use App\Domain\Exception\ConflictException;
use App\Domain\Exception\RateLimitException;
use App\Domain\Exception\ValidationException;
use App\Tests\Fake\InMemoryBatchRepository;
use App\Tests\Fake\InMemoryRateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CreateBatchUseCaseTest extends TestCase {
    private InMemoryBatchRepository $batches;
    private InMemoryRateLimiter $rateLimiter;
    private CreateBatchUseCase $sut;

    protected function setUp(): void {
        $this->batches = new InMemoryBatchRepository();
        $this->rateLimiter = new InMemoryRateLimiter();
        $this->sut = new CreateBatchUseCase($this->batches, $this->rateLimiter);
    }

    public function test_it_creates_one_message_for_one_recipient(): void {
        $result = $this->sut->run($this->client(), self::input(['a@exemplo.com']));

        self::assertSame(['batch_id' => 1, 'message_ids' => [1], 'is_new' => true], $result);
        self::assertSame(['a@exemplo.com'], $this->batches->saved[0]['recipients']);
    }

    public function test_it_fans_out_one_message_per_recipient_in_order(): void {
        $recipients = ['a@exemplo.com', 'b@exemplo.com', 'c@exemplo.com'];

        $result = $this->sut->run($this->client(), self::input($recipients));

        self::assertSame([1, 2, 3], $result['message_ids']);
        self::assertSame($recipients, $this->batches->saved[0]['recipients']);
        self::assertCount(1, $this->batches->saved);
    }

    public function test_it_drops_duplicate_recipients_keeping_the_first_occurrence(): void {
        $result = $this->sut->run($this->client(), self::input(['a@exemplo.com', 'b@exemplo.com', 'a@exemplo.com']));

        self::assertSame([1, 2], $result['message_ids']);
        self::assertSame(['a@exemplo.com', 'b@exemplo.com'], $this->batches->saved[0]['recipients']);
    }

    public function test_it_reuses_the_batch_for_the_same_key_and_payload(): void {
        $input = self::input(idempotencyKey: 'ABC-1');

        $first = $this->sut->run($this->client(), $input);
        $second = $this->sut->run($this->client(), $input);

        self::assertSame($first['batch_id'], $second['batch_id']);
        self::assertSame($first['message_ids'], $second['message_ids']);
        self::assertFalse($second['is_new']);
        self::assertCount(1, $this->batches->saved);
    }

    public function test_it_rejects_the_same_key_with_a_different_subject(): void {
        $this->sut->run($this->client(), self::input(idempotencyKey: 'ABC-1'));

        $this->expectException(ConflictException::class);

        $this->sut->run($this->client(), self::input(idempotencyKey: 'ABC-1', subject: 'Outro assunto'));
    }

    public function test_it_rejects_the_same_key_with_different_recipients(): void {
        $this->sut->run($this->client(), self::input(['a@exemplo.com'], idempotencyKey: 'ABC-1'));

        $this->expectException(ConflictException::class);

        $this->sut->run($this->client(), self::input(['b@exemplo.com'], idempotencyKey: 'ABC-1'));
    }

    public function test_it_rejects_the_same_key_with_reordered_recipients(): void {
        $this->sut->run($this->client(), self::input(['a@exemplo.com', 'b@exemplo.com'], idempotencyKey: 'ABC-1'));

        $this->expectException(ConflictException::class);

        $this->sut->run($this->client(), self::input(['b@exemplo.com', 'a@exemplo.com'], idempotencyKey: 'ABC-1'));
    }

    public function test_it_reuses_the_batch_after_the_content_is_purged(): void {
        $input = self::input(idempotencyKey: 'ABC-1');

        $first = $this->sut->run($this->client(), $input);
        $this->purgeSavedBatch();

        $second = $this->sut->run($this->client(), $input);

        self::assertSame($first['batch_id'], $second['batch_id']);
        self::assertSame($first['message_ids'], $second['message_ids']);
        self::assertFalse($second['is_new']);
    }

    public function test_it_rejects_a_different_payload_after_the_content_is_purged(): void {
        $this->sut->run($this->client(), self::input(idempotencyKey: 'ABC-1'));
        $this->purgeSavedBatch();

        $this->expectException(ConflictException::class);

        $this->sut->run($this->client(), self::input(['b@exemplo.com'], idempotencyKey: 'ABC-1'));
    }

    public function test_it_rejects_the_same_key_when_the_purged_batch_has_no_hash(): void {
        $input = self::input(idempotencyKey: 'ABC-1');
        $this->sut->run($this->client(), $input);
        $this->purgeSavedBatch();
        $this->removeSavedPayloadHash();

        $this->expectException(ConflictException::class);

        $this->sut->run($this->client(), $input);
    }

    public function test_it_compares_an_old_batch_without_a_hash_using_the_stored_content(): void {
        $input = self::input(idempotencyKey: 'ABC-1');

        $first = $this->sut->run($this->client(), $input);
        $this->removeSavedPayloadHash();

        $second = $this->sut->run($this->client(), $input);

        self::assertSame($first['batch_id'], $second['batch_id']);
        self::assertFalse($second['is_new']);
    }

    public function test_it_rejects_a_different_payload_for_an_old_batch_without_a_hash(): void {
        $this->sut->run($this->client(), self::input(idempotencyKey: 'ABC-1'));
        $this->removeSavedPayloadHash();

        $this->expectException(ConflictException::class);

        $this->sut->run($this->client(), self::input(['b@exemplo.com'], idempotencyKey: 'ABC-1'));
    }

    public function test_it_saves_a_new_batch_for_each_request_without_a_key(): void {
        $this->sut->run($this->client(), self::input());
        $this->sut->run($this->client(), self::input());

        self::assertCount(2, $this->batches->saved);
    }

    public function test_it_generates_the_text_from_the_html_when_missing(): void {
        $this->sut->run($this->client(), self::input(html: '<p>Olá <b>cliente</b></p>'));

        self::assertSame('Olá cliente', $this->batches->saved[0]['text']);
    }

    public function test_it_keeps_the_text_when_provided(): void {
        $this->sut->run($this->client(), self::input(text: 'texto puro'));

        self::assertSame('texto puro', $this->batches->saved[0]['text']);
    }

    public function test_it_hashes_the_normalized_payload(): void {
        $this->sut->run($this->client(), self::input(
            ['a@exemplo.com', 'a@exemplo.com'],
            html: '<p>Olá <b>cliente</b></p>',
        ));

        self::assertSame(
            BatchPayloadHash::of('Seu pedido saiu', '<p>Olá <b>cliente</b></p>', 'Olá cliente', 'loja@exemplo.com', ['a@exemplo.com']),
            $this->batches->saved[0]['payload_hash'],
        );
    }

    public function test_it_uses_the_client_sender_when_from_is_missing(): void {
        $this->sut->run($this->client(), self::input());

        self::assertSame('loja@exemplo.com', $this->batches->saved[0]['from_email']);
    }

    public function test_it_uses_the_request_sender_when_provided(): void {
        $this->sut->run($this->client(), self::input(from: 'outro@exemplo.com'));

        self::assertSame('outro@exemplo.com', $this->batches->saved[0]['from_email']);
    }

    public function test_it_counts_subject_length_in_characters(): void {
        $result = $this->sut->run($this->client(), self::input(subject: str_repeat('á', 255)));

        self::assertTrue($result['is_new']);
    }

    public function test_it_rejects_the_request_when_the_client_is_over_the_rate_limit(): void {
        $this->rateLimiter->allowed = false;

        $this->expectException(RateLimitException::class);

        $this->sut->run($this->client(), self::input());
    }

    /** @param CreateBatchInput $input */
    #[DataProvider('invalidInputs')]
    public function test_it_rejects_invalid_input(CreateBatchInput $input): void {
        $this->expectException(ValidationException::class);

        $this->sut->run($this->client(), $input);
    }

    /** @return array<string, array{CreateBatchInput}> */
    public static function invalidInputs(): array {
        return [
            'empty subject' => [self::input(subject: '')],
            'subject over 255 characters' => [self::input(subject: str_repeat('á', 256))],
            'empty html' => [self::input(html: '')],
            'html over 256 KB' => [self::input(html: str_repeat('a', 262145))],
            'no recipients' => [self::input([])],
            'over 1000 recipients' => [self::input(array_map(
                static fn (int $index): string => sprintf('user%d@exemplo.com', $index),
                range(1, 1001),
            ))],
            'invalid recipient' => [self::input(['nao-e-email'])],
            'non string recipient' => [self::input([123])],
            'from over 320 characters' => [self::input(from: str_repeat('a', 309) . '@exemplo.com')],
            'empty idempotency key' => [self::input(idempotencyKey: '')],
            'idempotency key over 128 characters' => [self::input(idempotencyKey: str_repeat('a', 129))],
        ];
    }

    /**
     * @param array<array-key, mixed> $recipients
     */
    private static function input(
        array $recipients = ['a@exemplo.com'],
        ?string $idempotencyKey = null,
        ?string $text = null,
        ?string $from = null,
        string $subject = 'Seu pedido saiu',
        string $html = '<p>Olá</p>',
    ): CreateBatchInput {
        return new CreateBatchInput(
            subject: $subject,
            html: $html,
            text: $text,
            from: $from,
            recipients: $recipients,
            idempotencyKey: $idempotencyKey,
        );
    }

    private function purgeSavedBatch(): void {
        $saved = $this->batches->saved[0];
        $saved['html'] = '';
        $saved['text'] = '';
        $saved['html_purged_at'] = '2026-10-01T00:00:00+00:00';
        $this->batches->saved[0] = $saved;
    }

    private function removeSavedPayloadHash(): void {
        $saved = $this->batches->saved[0];
        $saved['payload_hash'] = null;
        $this->batches->saved[0] = $saved;
    }

    private function client(): Client {
        return new Client(
            id: 7,
            name: 'Loja',
            apiKeyHash: hash('sha256', 'chave'),
            fromEmail: 'loja@exemplo.com',
            fromName: 'Loja',
            rateLimitPerSec: 10,
            isActive: true,
        );
    }
}
