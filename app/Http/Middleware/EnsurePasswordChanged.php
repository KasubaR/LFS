<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user && $user->must_change_password && ! $request->routeIs('password.change', 'password.change.store', 'logout', 'verification.*')) {
            return redirect()->route('password.change');
        }

        return $next($request);
    }
}
