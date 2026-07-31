<?php

namespace App\Http\Middleware;

use App\Services\ApiClientService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the Bearer credential for the partner API and binds the client to the
 * request as `api_client`. Stateless — no session is touched.
 */
class AuthenticateApiClient
{
    public const ATTRIBUTE = 'api_client';

    public function __construct(private readonly ApiClientService $clients) {}

    public function handle(Request $request, Closure $next): Response
    {
        $client = $this->clients->resolveToken($request->bearerToken());

        // Unknown key and wrong secret are deliberately indistinguishable.
        if ($client === null) {
            return $this->deny('unauthorized', 'Invalid or missing API credentials.', 401);
        }

        if ($client->isRevoked()) {
            return $this->deny('credential_revoked', 'This API key has been revoked.', 401);
        }

        if ($client->isExpired()) {
            return $this->deny('credential_expired', 'This API key has expired.', 401);
        }

        if (! $client->allowsIp($request->ip())) {
            return $this->deny('ip_not_allowed', 'Requests from this IP address are not permitted.', 403);
        }

        $request->attributes->set(self::ATTRIBUTE, $client);

        $this->clients->touchLastUsed($client);

        return $next($request);
    }

    private function deny(string $code, string $message, int $status): Response
    {
        $response = response()->json([
            'error' => ['code' => $code, 'message' => $message],
        ], $status);

        if ($status === 401) {
            $response->headers->set('WWW-Authenticate', 'Bearer');
        }

        return $response;
    }
}
