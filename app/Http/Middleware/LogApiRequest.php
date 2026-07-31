<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use App\Models\ApiRequestLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Audit trail for the partner API: which site looked up a membership, and when.
 * Records the lookup outcome, never the identifiers that were submitted.
 */
class LogApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);

        $response = $next($request);

        try {
            $client = $request->attributes->get(AuthenticateApiClient::ATTRIBUTE);

            ApiRequestLog::query()->create([
                'api_client_id' => $client instanceof ApiClient ? $client->id : null,
                'method' => $request->getMethod(),
                'path' => mb_substr($request->path(), 0, 255),
                'status' => $response->getStatusCode(),
                'ip' => $request->ip(),
                'result' => $this->resultFrom($response),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        } catch (Throwable) {
            // Auditing must never break a partner integration.
        }

        return $response;
    }

    private function resultFrom(Response $response): ?string
    {
        $content = $response->getContent();
        if ($content === false || $content === '') {
            return null;
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            return null;
        }

        $result = $decoded['data']['status'] ?? $decoded['error']['code'] ?? null;

        return is_string($result) ? mb_substr($result, 0, 30) : null;
    }
}
