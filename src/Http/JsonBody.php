<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Exception\ValidationException;
use JsonException;

final class JsonBody{
    private const MEGABYTE = 1024 * 1024;
    // Guard against oversized bodies (2 MB); business limits (256 KB of HTML) belong to CreateBatchUseCase.
    public const MAX_BYTES = 2 * self::MEGABYTE;
    private const JSON_MAX_DEPTH = 512;

    /** @return array<string, mixed> */
    public static function parse(string $raw): array{
        if (strlen($raw) > self::MAX_BYTES) {
            throw new ValidationException('payload too large');
        }

        try {
            $data = json_decode($raw, true, self::JSON_MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ValidationException('body must be a JSON object');
        }

        if (!is_array($data) || (array_is_list($data) && $data !== [])) {
            throw new ValidationException('body must be a JSON object');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
