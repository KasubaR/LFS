<?php

namespace App\Services;

use App\Enums\AdminRole;
use App\Models\AdminUser;
use Illuminate\Http\Request;

class AdminPermissionService
{
    public const LEVEL_NONE = 'none';

    public const LEVEL_READ = 'read';

    public const LEVEL_WRITE = 'write';

    public function currentAdmin(?Request $request = null): ?AdminUser
    {
        $request ??= request();
        $id = $request->session()->get(config('admin.session_user_key'));

        if (! $id) {
            return null;
        }

        /** @var AdminUser|null $cached */
        $cached = $request->attributes->get('admin_user');
        if ($cached instanceof AdminUser && (int) $cached->id === (int) $id) {
            return $cached;
        }

        $admin = AdminUser::query()
            ->with('satellites')
            ->whereKey($id)
            ->where('is_active', true)
            ->first();

        if ($admin) {
            $request->attributes->set('admin_user', $admin);
        }

        return $admin;
    }

    public function levelFor(?AdminUser $admin, string $section): string
    {
        if ($admin === null) {
            return self::LEVEL_NONE;
        }

        $matrix = config('admin_permissions.matrix.'.$admin->role, []);

        return (string) ($matrix[$section] ?? self::LEVEL_NONE);
    }

    public function can(?AdminUser $admin, string $section, string $level = self::LEVEL_READ): bool
    {
        if ($admin === null || $section === '') {
            return false;
        }

        $have = $this->levelFor($admin, $section);

        if ($have === self::LEVEL_NONE) {
            return false;
        }

        if ($level === self::LEVEL_READ) {
            return in_array($have, [self::LEVEL_READ, self::LEVEL_WRITE], true);
        }

        if ($level === self::LEVEL_WRITE) {
            return $have === self::LEVEL_WRITE;
        }

        return false;
    }

    public function canAccessRequest(?AdminUser $admin, Request $request): bool
    {
        if ($admin === null) {
            return false;
        }

        $section = $this->sectionForPath($request->path());

        // Profile is available to any authenticated admin.
        if ($section === null && $this->isProfilePath($request->path())) {
            return true;
        }

        if ($section === null) {
            return $admin->isSuperAdmin();
        }

        $needsWrite = ! in_array(strtoupper($request->method()), ['GET', 'HEAD', 'OPTIONS'], true);

        if ($needsWrite && $admin->isReadOnlyAuditor()) {
            return false;
        }

        return $this->can(
            $admin,
            $section,
            $needsWrite ? self::LEVEL_WRITE : self::LEVEL_READ
        );
    }

    public function sectionForPath(string $path): ?string
    {
        $path = trim($path, '/');
        if (! str_starts_with($path, 'admin/')) {
            return null;
        }

        $parts = explode('/', $path);
        $segment = $parts[1] ?? '';

        if ($segment === '' || $segment === config('admin.login_slug') || $segment === 'logout') {
            return null;
        }

        $map = config('admin_permissions.route_sections', []);

        if (! array_key_exists($segment, $map)) {
            return null;
        }

        return $map[$segment];
    }

    /**
     * @return list<int>|null null means no satellite restriction
     */
    public function memberSatelliteScope(?AdminUser $admin): ?array
    {
        if ($admin === null || ! $admin->isSatelliteAdministrator()) {
            return null;
        }

        return $admin->satelliteIds();
    }

    public function canAccessMember(?AdminUser $admin, ?int $memberSatelliteId): bool
    {
        $scope = $this->memberSatelliteScope($admin);
        if ($scope === null) {
            return $this->can($admin, 'members', self::LEVEL_READ);
        }

        if ($memberSatelliteId === null) {
            return false;
        }

        return in_array($memberSatelliteId, $scope, true);
    }

    private function isProfilePath(string $path): bool
    {
        $path = trim($path, '/');

        return $path === 'admin/profile' || str_starts_with($path, 'admin/profile/');
    }
}
