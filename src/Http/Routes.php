<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\Controller\CreateBatchController;
use App\Http\Controller\GetMessageController;

final class Routes {
    public static function register(
        Dispatcher $dispatcher,
        CreateBatchController $createBatch,
        GetMessageController $getMessage,
    ): void {
        $dispatcher->add('POST', '/messages', $createBatch->create(...));
        $dispatcher->add('GET', '/messages/{id}', $getMessage->get(...));
    }
}
