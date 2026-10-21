<?php

declare(strict_types=1);

use App\Application\CreateBatchUseCase;
use App\Application\GetMessageUseCase;
use App\Http\Auth;
use App\Http\Controller\CreateBatchController;
use App\Http\Controller\GetMessageController;
use App\Http\Dispatcher;
use App\Http\ErrorHandler;
use App\Http\Request;
use App\Http\Routes;
use App\Infrastructure\Env;
use App\Infrastructure\Log\JsonLogger;
use App\Infrastructure\Persistence\PdoBatchRepository;
use App\Infrastructure\Persistence\PdoClientRepository;
use App\Infrastructure\Persistence\PdoMessageRepository;

require dirname(__DIR__) . '/vendor/autoload.php';

$errors = new ErrorHandler(JsonLogger::stdout());

try {
    $pdo = new PDO(
        sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            Env::get('POSTGRES_HOST'),
            Env::get('POSTGRES_PORT'),
            Env::get('POSTGRES_DB'),
        ),
        Env::get('POSTGRES_USER'),
        Env::get('POSTGRES_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    $createBatch = new CreateBatchController(
        new Auth(new PdoClientRepository($pdo)),
        new CreateBatchUseCase(new PdoBatchRepository($pdo)),
    );

    $getMessage = new GetMessageController(
        new Auth(new PdoClientRepository($pdo)),
        new GetMessageUseCase(new PdoMessageRepository($pdo)),
    );

    $dispatcher = new Dispatcher();
    Routes::register($dispatcher, $createBatch, $getMessage);

    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $request = Request::fromGlobals();
    $response = $dispatcher->dispatch(
        (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        is_string($path) ? $path : '/',
        $request,
    );
} catch (Throwable $exception) {
    $response = $errors->toResponse($exception);
}

http_response_code($response['status']);
header('Content-Type: application/json');
echo json_encode($response['body'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
