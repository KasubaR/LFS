<?php

namespace App\Http\Middleware;

use App\Services\AdminPermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminCan
{
    public function __construct(
        private readonly AdminPermissionService $permissions,
    ) {}

    /**
     * @param  string  $section  Permission section key
     * @param  string  $level  read|write
     */
    public function handle(Request $request, Closure $next, string $section, string $level = 'read'): Response
    {
        $admin = $this->permissions->currentAdmin($request);

        if ($admin === null) {
            return redirect('/admin/'.config('admin.login_slug'));
        }

        $needsWrite = $level === 'write'
            || ! in_array(strtoupper($request->method()), ['GET', 'HEAD', 'OPTIONS'], true);

        if ($needsWrite && $admin->isReadOnlyAuditor()) {
            abort(403, 'Read-only auditors cannot modify data.');
        }

        $required = $needsWrite ? AdminPermissionService::LEVEL_WRITE : $level;

        if (! $this->permissions->can($admin, $section, $required)) {
            abort(403, 'You do not have permission to access this section.');
        }

        return $next($request);
    }
}
