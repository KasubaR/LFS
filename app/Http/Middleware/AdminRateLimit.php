<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class AdminRateLimit
{
    private const WINDOW_SECONDS = 60;

    private const MAX_POSTS_PER_WINDOW = 60;

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST')) {
            return $next($request);
        }

        $loginSlug = config('admin.login_slug');
        if ($request->is('admin/'.$loginSlug)) {
            return $next($request);
        }

        $key = 'lfs_admin_post:'.sha1(session()->getId());
        $executed = RateLimiter::attempt(
            $key,
            self::MAX_POSTS_PER_WINDOW,
            fn () => true,
            self::WINDOW_SECONDS
        );

        if (! $executed) {
            return response(
                'Too Many Requests: admin write rate limit exceeded. Please wait a moment and try again.',
                429,
                ['Retry-After' => (string) self::WINDOW_SECONDS]
            );
        }

        return $next($request);
    }
}
