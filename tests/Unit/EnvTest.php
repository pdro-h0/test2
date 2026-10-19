<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Infrastructure\Env;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EnvTest extends TestCase{
    public function test_it_throws_when_variable_is_missing(): void{
        self::expectException(RuntimeException::class);

        Env::get('ENV_TEST_MISSING');
    }

    public function test_it_throws_when_variable_is_empty(): void{
        putenv('ENV_TEST_EMPTY=');

        self::expectException(RuntimeException::class);

        Env::get('ENV_TEST_EMPTY');
    }

    public function test_it_returns_the_value_when_present(): void{
        putenv('ENV_TEST_PRESENT=value');

        self::assertSame('value', Env::get('ENV_TEST_PRESENT'));
    }
}
