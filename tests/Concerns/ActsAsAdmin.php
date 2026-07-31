<?php

namespace Tests\Concerns;

use App\Enums\AdminRole;
use App\Models\AdminUser;

trait ActsAsAdmin
{
    protected function actingAsAdmin(?AdminUser $admin = null): static
    {
        $admin ??= $this->makeAdminUser();

        return $this->withSession([
            config('admin.session_auth_key') => true,
            config('admin.session_active_key') => time(),
            config('admin.session_user_key') => $admin->id,
        ]);
    }

    protected function makeAdminUser(array $overrides = []): AdminUser
    {
        return AdminUser::query()->create(array_merge([
            'name' => 'Test Super Admin',
            'email' => 'admin'.uniqid('', true).'@lfs.test',
            'password' => 'password',
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ], $overrides));
    }

    /**
     * @param  list<int>  $satelliteIds
     */
    protected function makeSatelliteAdmin(array $satelliteIds, array $overrides = []): AdminUser
    {
        $admin = $this->makeAdminUser(array_merge([
            'role' => AdminRole::SatelliteAdministrator,
            'name' => 'Satellite Admin',
        ], $overrides));

        $admin->satellites()->sync($satelliteIds);

        return $admin->fresh(['satellites']);
    }

    protected function adminLoginPath(): string
    {
        return '/admin/'.config('admin.login_slug', 'door');
    }
}
