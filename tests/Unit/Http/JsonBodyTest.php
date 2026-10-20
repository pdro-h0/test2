<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Domain\Exception\ValidationException;
use App\Http\JsonBody;
use PHPUnit\Framework\TestCase;

final class JsonBodyTest extends TestCase{
    public function test_it_returns_the_object_as_an_array(): void{
        self::assertSame(
            ['subject' => 'Oi', 'recipients' => ['a@exemplo.com']],
            JsonBody::parse('{"subject":"Oi","recipients":["a@exemplo.com"]}'),
        );
    }

    public function test_it_rejects_invalid_json(): void{
        $this->expectException(ValidationException::class);

        JsonBody::parse('{"subject":');
    }

    public function test_it_rejects_a_non_object_top_level(): void{
        $this->expectException(ValidationException::class);

        JsonBody::parse('[1, 2]');
    }

    public function test_it_rejects_a_body_over_the_limit(): void{
        $this->expectException(ValidationException::class);

        JsonBody::parse(str_repeat('a', JsonBody::MAX_BYTES + 1));
    }
}
