<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\CreateBatchInput;
use App\Application\CreateBatchUseCase;
use App\Domain\Exception\ValidationException;
use App\Http\Auth;
use App\Http\JsonBody;
use App\Http\Request;

final class CreateBatchController {
    public function __construct(
        private readonly Auth $auth,
        private readonly CreateBatchUseCase $useCase,
    ) {
    }

    /** @return array{status:int, body:array{batch_id:int, message_ids:list<int>}} */
    public function create(Request $request): array {
        $client = $this->auth->authenticate($request->authorization);
        $data = JsonBody::parse($request->rawBody);

        $result = $this->useCase->run($client, new CreateBatchInput(
            subject: $this->string($data, 'subject'),
            html: $this->string($data, 'html'),
            text: $this->nullableString($data, 'text'),
            from: $this->nullableString($data, 'from'),
            recipients: $this->recipients($data),
            idempotencyKey: $request->idempotencyKey,
        ));

        return [
            'status' => $result['is_new'] ? 202 : 200,
            'body' => ['batch_id' => $result['batch_id'], 'message_ids' => $result['message_ids']],
        ];
    }

    /** @param array<string, mixed> $data */
    private function string(array $data, string $key): string {
        $value = $data[$key] ?? '';

        // Missing values fall through as '' so CreateBatchUseCase owns the "is required" message.
        if (!is_string($value)) {
            throw new ValidationException(sprintf('%s must be a string', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function nullableString(array $data, string $key): ?string {
        if (!isset($data[$key])) {
            return null;
        }

        return $this->string($data, $key);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<array-key, mixed>
     */
    private function recipients(array $data): array {
        $value = $data['recipients'] ?? [];

        if (!is_array($value)) {
            throw new ValidationException('recipients must be a list');
        }

        return $value;
    }
}
