<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait RespondsWithJson
{
    protected function wantsJson(Request $request): bool
    {
        return $request->expectsJson()
            || strtolower((string) $request->header('X-Requested-With', '')) === 'xmlhttprequest';
    }

    protected function jsonResponse(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected function jsonError(string $message, int $status = 400): JsonResponse
    {
        return $this->jsonResponse(['ok' => false, 'message' => $message], $status);
    }
}
