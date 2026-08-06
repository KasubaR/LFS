<?php

namespace App\Http\Middleware;

use App\Services\MembershipService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mirrors EnsurePasswordChanged's forced-flow pattern: a member with an
 * outstanding balance on an otherwise-active, not-yet-expired membership is
 * redirected to /account/balance until they clear it, instead of using the
 * rest of the account area.
 */
class EnsureBalancePaid
{
    public function __construct(private readonly MembershipService $membershipService) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (
            $user
            && ! $request->routeIs('account.balance', 'account.payment.*', 'logout', 'verification.*', 'password.change*')
            && $this->membershipService->findOutstandingBalancePayment((int) $user->id) !== null
        ) {
            return redirect()->route('account.balance');
        }

        return $next($request);
    }
}
