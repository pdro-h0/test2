<?php

declare(strict_types=1);

namespace App\Infrastructure;

use RuntimeException;

final class Env{
    public static function get(string $key): string{
        $value = getenv($key);
        if ($value === false || $value === '') {
            throw new RuntimeException(sprintf('Missing environment variable: %s', $key));
        }

        return $value;
    }
}
