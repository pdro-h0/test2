<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Exception\MethodNotAllowedException;
use App\Domain\Exception\NotFoundException;
use Closure;

final class Dispatcher{
    /** @var array<string, array<string, Closure(): array{status:int, body:array<string, mixed>}>> */
    private array $routes = [];

    /** @param Closure(): array{status:int, body:array<string, mixed>} $handler */
    public function add(string $method, string $path, Closure $handler): void{
        $this->routes[$path][strtoupper($method)] = $handler;
    }

    /** @return array{status:int, body:array<string, mixed>} */
    public function dispatch(string $method, string $path): array{
        if (!isset($this->routes[$path])) throw new NotFoundException('not found');

        $handler = $this->routes[$path][strtoupper($method)] ?? null;
        if ($handler === null) {
            throw new MethodNotAllowedException('method not allowed');
        }

        return $handler();
    }
}
