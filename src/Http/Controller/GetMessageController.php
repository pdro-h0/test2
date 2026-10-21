<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\GetMessageUseCase;
use App\Http\Auth;
use App\Http\Request;

final class GetMessageController {
    public function __construct(
        private readonly Auth $auth,
        private readonly GetMessageUseCase $useCase,
    ) {
    }

    /** @return array{status:int, body:array{status:string, attempts:int, last_error:?string, created_at:string, sent_at:?string}} */
    public function get(Request $request): array {
        $client = $this->auth->authenticate($request->authorization);
        
        $result = $this->useCase->run($client, (int) ($request->pathParams['id'] ?? '0'));

        return [
            'status' => 200,
            'body' => $result,
        ];
    }
}
