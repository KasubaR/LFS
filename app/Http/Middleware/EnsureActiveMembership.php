<?php

namespace App\Http\Middleware;

use App\Services\MembershipService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hard-gate: user must have a currently active (non-expired) membership.
 *
 * Registered as `membership.active`. Most member account pages intentionally
 * use soft gating instead (AccountController hasPaidMembership / empty
 * document lists) so unpaid members can still reach the dashboard and pay.
 * Apply this alias only to routes that must reject unpaid/expired users.
 */
class EnsureActiveMembership
{
    public function __construct(
        private readonly MembershipService $membershipService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user && ! $this->membershipService->userHasActiveMembership((int) $user->id)) {
            return redirect()->route('account')
                ->with('auth_status', 'An active membership is required to access that page.');
        }

        return $next($request);
    }
}
