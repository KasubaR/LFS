<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a partner route on a scope granted to the calling client.
 * Usage: ->middleware('api.scope:members:verify')
 */
class RequireApiScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $client = $request->attributes->get(AuthenticateApiClient::ATTRIBUTE);

        if (! $client instanceof ApiClient || ! $client->hasScope($scope)) {
            return response()->json([
                'error' => [
                    'code' => 'insufficient_scope',
                    'message' => 'This API key does not have the "'.$scope.'" scope.',
                ],
            ], 403);
        }

        return $next($request);
    }
}
