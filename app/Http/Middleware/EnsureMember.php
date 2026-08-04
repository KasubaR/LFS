<?php

namespace App\Http\Middleware;

use App\Models\Membership;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires an authenticated user who has at least one membership row.
 *
 * Apply after `auth` + `verified` (+ password-change gates). Users without a
 * membership are sent to /membership/apply. Active-card checks use the separate
 * `membership.active` alias (soft-gated on documents/announcements via
 * AccountController instead of hard redirects).
 */
class EnsureMember
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return redirect()->guest('/login');
        }

        if (! Membership::query()->where('user_id', Auth::id())->exists()) {
            return redirect()->route('membership.apply');
        }

        return $next($request);
    }
}
