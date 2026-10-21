<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Exception\MethodNotAllowedException;
use App\Domain\Exception\NotFoundException;
use Closure;

final class Dispatcher{
    /** @var list<array{regex: string, methods: array<string, Closure(Request): array{status:int, body:array<string, mixed>}>}> */
    private array $routes = [];

    /** @param Closure(Request): array{status:int, body:array<string, mixed>} $handler */
    public function add(string $method, string $path, Closure $handler): void{
        // Paths are code literals, so {name} is replaced without preg_quote.
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $path) . '$#';

        foreach ($this->routes as $index => $route) {
            if ($route['regex'] === $regex) {
                $this->routes[$index]['methods'][strtoupper($method)] = $handler;
                return;
            }
        }

        $this->routes[] = ['regex' => $regex, 'methods' => [strtoupper($method) => $handler]];
    }

    /** @return array{status:int, body:array<string, mixed>} */
    public function dispatch(string $method, string $path, Request $request): array{
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            $handler = $route['methods'][strtoupper($method)] ?? null;
            if ($handler === null) {
                throw new MethodNotAllowedException('method not allowed');
            }

            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

            return $handler($request->withPathParams($params));
        }

        throw new NotFoundException('not found');
    }
}
