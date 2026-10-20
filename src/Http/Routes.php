<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\Controller\CreateBatchController;

final class Routes {
    public static function register(Dispatcher $dispatcher, CreateBatchController $createBatch): void {
        $dispatcher->add('POST', '/messages', $createBatch->create(...));
    }
}
