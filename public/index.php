<?php

declare(strict_types=1);

use App\Http\ErrorHandler;
use App\Http\Router;
use App\Infrastructure\Log\JsonLogger;

require dirname(__DIR__) . '/vendor/autoload.php';

$errors = new ErrorHandler(JsonLogger::stdout());
$router = new Router();

try {
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $response = $router->dispatch(
        (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        is_string($path) ? $path : '/',
    );
} catch (Throwable $exception) {
    $response = $errors->toResponse($exception);
}

http_response_code($response['status']);
header('Content-Type: application/json');
echo json_encode($response['body'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
