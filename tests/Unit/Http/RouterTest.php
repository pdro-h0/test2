<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Domain\Exception\MethodNotAllowedException;
use App\Domain\Exception\NotFoundException;
use App\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase{
    public function test_it_dispatches_to_the_registered_handler(): void{
        $router = new Router();
        $router->add('POST', '/messages', static fn (): array => ['status' => 202, 'body' => ['batch_id' => 7]]);

        self::assertSame(['status' => 202, 'body' => ['batch_id' => 7]], $router->dispatch('POST', '/messages'));
    }

    public function test_it_rejects_an_unknown_path(): void{
        $this->expectException(NotFoundException::class);

        (new Router())->dispatch('GET', '/nada');
    }

    public function test_it_rejects_a_known_path_with_the_wrong_method(): void{
        $router = new Router();
        $router->add('POST', '/messages', static fn (): array => ['status' => 202, 'body' => []]);

        $this->expectException(MethodNotAllowedException::class);

        $router->dispatch('GET', '/messages');
    }
}
