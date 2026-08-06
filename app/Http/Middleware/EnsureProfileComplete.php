<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bulk-imported members can land with gaps the spreadsheet didn't have —
 * most commonly satellite (name in the sheet didn't match a known one), but
 * also t-shirt size, gender, phone, or a proper last name. Block the rest of
 * /account until those are filled in (see User::hasCompleteProfile()).
 *
 * Apply after `auth` + `verified` + `force.password.change`. Exempts the
 * personal-details page itself (that's where they fix it) and logout.
 */
class EnsureProfileComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user
            && ! $user->hasCompleteProfile()
            && ! $request->routeIs('account.settings.personal', 'account.settings.personal.update', 'logout')) {
            return redirect()->route('account.settings.personal')
                ->with('auth_status', 'Please complete your profile to continue.');
        }

        return $next($request);
    }
}
