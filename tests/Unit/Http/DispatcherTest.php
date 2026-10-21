<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Domain\Exception\MethodNotAllowedException;
use App\Domain\Exception\NotFoundException;
use App\Http\Dispatcher;
use App\Http\Request;
use PHPUnit\Framework\TestCase;

final class DispatcherTest extends TestCase{
    public function test_it_dispatches_to_the_registered_handler(): void{
        $dispatcher = new Dispatcher();
        $dispatcher->add('POST', '/messages', static fn (Request $request): array => ['status' => 202, 'body' => ['batch_id' => 7]]);

        self::assertSame(['status' => 202, 'body' => ['batch_id' => 7]], $dispatcher->dispatch('POST', '/messages', new Request(null, '', null)));
    }

    public function test_it_rejects_an_unknown_path(): void{
        $this->expectException(NotFoundException::class);

        (new Dispatcher())->dispatch('GET', '/nada', new Request(null, '', null));
    }

    public function test_it_rejects_a_known_path_with_the_wrong_method(): void{
        $dispatcher = new Dispatcher();
        $dispatcher->add('POST', '/messages', static fn (Request $request): array => ['status' => 202, 'body' => []]);

        $this->expectException(MethodNotAllowedException::class);

        $dispatcher->dispatch('GET', '/messages', new Request(null, '', null));
    }

    public function test_it_extracts_path_parameters(): void{
        $dispatcher = new Dispatcher();
        $dispatcher->add('POST', '/messages', static fn (Request $request): array => ['status' => 202, 'body' => []]);
        $dispatcher->add('GET', '/messages/{id}', static fn (Request $request): array => [
            'status' => 200,
            'body' => ['id' => $request->pathParams['id']],
        ]);

        self::assertSame(
            ['status' => 200, 'body' => ['id' => '42']],
            $dispatcher->dispatch('GET', '/messages/42', new Request(null, '', null)),
        );
    }

    public function test_it_rejects_a_known_pattern_with_the_wrong_method(): void{
        $dispatcher = new Dispatcher();
        $dispatcher->add('GET', '/messages/{id}', static fn (Request $request): array => ['status' => 200, 'body' => []]);

        $this->expectException(MethodNotAllowedException::class);

        $dispatcher->dispatch('POST', '/messages/42', new Request(null, '', null));
    }

    public function test_it_rejects_a_path_that_does_not_match_the_pattern(): void{
        $dispatcher = new Dispatcher();
        $dispatcher->add('GET', '/messages/{id}', static fn (Request $request): array => ['status' => 200, 'body' => []]);

        $this->expectException(NotFoundException::class);

        $dispatcher->dispatch('GET', '/messages/42/x', new Request(null, '', null));
    }
}
