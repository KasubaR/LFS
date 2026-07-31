<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use App\Services\AdminPermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminAuthenticated
{
    public function __construct(
        private readonly AdminPermissionService $permissions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $loginSlug = config('admin.login_slug');

        if ($this->isExemptRoute($request, $loginSlug)) {
            return $next($request);
        }

        if (! $this->isAuthenticated($request)) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['ok' => false, 'message' => 'Unauthenticated.'], 401);
            }

            return redirect('/admin/'.$loginSlug);
        }

        $admin = $this->permissions->currentAdmin($request);
        if ($admin === null) {
            $this->clearSession($request);

            return redirect('/admin/'.$loginSlug);
        }

        $request->session()->put(config('admin.session_active_key'), time());
        $request->attributes->set('admin_user', $admin);

        return $next($request);
    }

    private function isExemptRoute(Request $request, string $loginSlug): bool
    {
        if ($request->is('admin/logout')) {
            return true;
        }

        return $request->is('admin/'.$loginSlug) || $request->is('admin/'.$loginSlug.'/*');
    }

    private function isAuthenticated(Request $request): bool
    {
        $authKey = config('admin.session_auth_key');
        $userKey = config('admin.session_user_key');

        if (! $request->session()->get($authKey) || ! $request->session()->get($userKey)) {
            return false;
        }

        $activeKey = config('admin.session_active_key');
        $lastActive = (int) $request->session()->get($activeKey, 0);
        $timeout = (int) config('admin.session_timeout', 1800);

        if ((time() - $lastActive) > $timeout) {
            $this->clearSession($request);

            return false;
        }

        return true;
    }

    private function clearSession(Request $request): void
    {
        $request->session()->forget([
            config('admin.session_auth_key'),
            config('admin.session_active_key'),
            config('admin.session_user_key'),
        ]);
    }
}
