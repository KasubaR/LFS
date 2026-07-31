<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-client throughput cap. Also the brake on credential-holder brute force:
 * guessing surnames against sequential membership numbers costs one request each.
 */
class ApiClientRateLimit
{
    private const WINDOW_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $client = $request->attributes->get(AuthenticateApiClient::ATTRIBUTE);

        if (! $client instanceof ApiClient) {
            return $next($request);
        }

        $limit = max(1, (int) $client->rate_limit_per_minute);
        $key = 'lfs_api_client:'.$client->key_id;

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $retryAfter = RateLimiter::availableIn($key);

            return response()->json([
                'error' => [
                    'code' => 'rate_limited',
                    'message' => 'Rate limit exceeded. Retry in '.$retryAfter.' seconds.',
                ],
            ], 429, [
                'Retry-After' => (string) $retryAfter,
                'X-RateLimit-Limit' => (string) $limit,
                'X-RateLimit-Remaining' => '0',
            ]);
        }

        RateLimiter::hit($key, self::WINDOW_SECONDS);

        $response = $next($request);

        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', (string) RateLimiter::remaining($key, $limit));

        return $response;
    }
}
