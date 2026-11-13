<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminUserRequest;
use App\Http\Requests\Admin\UpdateAdminUserRequest;
use App\Models\AdminUser;
use App\Models\Satellite;
use App\Services\AdminPermissionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function __construct(
        private readonly AdminPermissionService $permissions,
    ) {}

    public function index(): View
    {
        $users = AdminUser::query()
            ->with('satellites')
            ->orderBy('name')
            ->get();

        return view('admin.users.index', [
            'pageTitle' => 'Admin Users',
            'activePage' => 'users',
            'users' => $users,
            'counts' => $this->emptyCounts(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.users.form', [
            'pageTitle' => 'Create Admin User',
            'activePage' => 'users',
            'admin' => null,
            'roles' => $this->assignableRoles($request),
            'satelliteRole' => AdminRole::SatelliteAdministrator,
            'satellites' => Satellite::query()->where('is_active', true)->orderBy('name')->get(),
            'selectedSatelliteIds' => [],
            'counts' => $this->emptyCounts(),
        ]);
    }

    public function store(StoreAdminUserRequest $request): RedirectResponse
    {
        $data = $request->validated();

        if ($denied = $this->denyIfNotSuperAdmin($request, null, $data['role'])) {
            return $denied;
        }

        $admin = AdminUser::query()->create([
            'name' => $data['name'],
            'email' => strtolower(trim($data['email'])),
            'password' => $data['password'],
            'role' => $data['role'],
            'is_active' => true,
        ]);

        $this->syncSatellites($admin, $data['role'], $data['satellite_ids'] ?? []);

        return redirect('/admin/users')
            ->with('flash', ['success' => 'Admin user created.']);
    }

    public function edit(Request $request, int $id): View|RedirectResponse
    {
        $admin = AdminUser::query()->with('satellites')->findOrFail($id);

        if ($denied = $this->denyIfNotSuperAdmin($request, $admin)) {
            return $denied;
        }

        return view('admin.users.form', [
            'pageTitle' => 'Edit Admin User',
            'activePage' => 'users',
            'admin' => $admin,
            'roles' => $this->assignableRoles($request),
            'satelliteRole' => AdminRole::SatelliteAdministrator,
            'satellites' => Satellite::query()->where('is_active', true)->orderBy('name')->get(),
            'selectedSatelliteIds' => $admin->satelliteIds(),
            'counts' => $this->emptyCounts(),
        ]);
    }

    public function update(UpdateAdminUserRequest $request, int $id): RedirectResponse
    {
        $admin = AdminUser::query()->findOrFail($id);
        $data = $request->validated();

        if ($denied = $this->denyIfNotSuperAdmin($request, $admin, $data['role'])) {
            return $denied;
        }

        $admin->fill([
            'name' => $data['name'],
            'email' => strtolower(trim($data['email'])),
            'role' => $data['role'],
        ]);

        if (! empty($data['password'])) {
            $admin->password = $data['password'];
        }

        $admin->save();
        $this->syncSatellites($admin, $data['role'], $data['satellite_ids'] ?? []);

        return redirect('/admin/users')
            ->with('flash', ['success' => 'Admin user updated.']);
    }

    public function deactivate(Request $request, int $id): RedirectResponse
    {
        $admin = AdminUser::query()->findOrFail($id);
        $current = $this->permissions->currentAdmin($request);

        if ($denied = $this->denyIfNotSuperAdmin($request, $admin)) {
            return $denied;
        }

        if ($current && (int) $current->id === (int) $admin->id) {
            return redirect('/admin/users')
                ->with('flash', ['error' => 'You cannot deactivate your own account.']);
        }

        $admin->forceFill(['is_active' => false])->save();

        return redirect('/admin/users')
            ->with('flash', ['success' => 'Admin user deactivated.']);
    }

    public function activate(Request $request, int $id): RedirectResponse
    {
        $admin = AdminUser::query()->findOrFail($id);

        if ($denied = $this->denyIfNotSuperAdmin($request, $admin)) {
            return $denied;
        }

        $admin->forceFill(['is_active' => true])->save();

        return redirect('/admin/users')
            ->with('flash', ['success' => 'Admin user activated.']);
    }

    /**
     * Clears an admin's TOTP enrollment so they set it up fresh on next
     * login — the recovery path when an EC/Super Admin account loses its
     * authenticator device. Only reachable by Super Admin (admin_users
     * write), and re-enrollment still requires the account's own password
     * plus a confirmed 6-digit code, so this alone can't grant access.
     */
    public function resetTotp(Request $request, int $id): RedirectResponse
    {
        $admin = AdminUser::query()->findOrFail($id);

        if ($denied = $this->denyIfNotSuperAdmin($request, $admin)) {
            return $denied;
        }
        $admin->forceFill(['totp_secret' => null, 'totp_confirmed_at' => null])->save();

        return redirect('/admin/users')
            ->with('flash', ['success' => "Two-factor authentication reset for {$admin->name}. They will set it up again on next login."]);
    }

    /**
     * Only a Super Admin may create, edit, deactivate or reset a Super Admin,
     * or give anyone the Super Admin role (so an Admin cannot promote
     * themselves or take over a Super Admin account).
     */
    private function denyIfNotSuperAdmin(Request $request, ?AdminUser $target, ?string $newRole = null): ?RedirectResponse
    {
        $current = $this->permissions->currentAdmin($request);
        if ($current?->isSuperAdmin()) {
            return null;
        }

        if (($target && $target->isSuperAdmin()) || $newRole === AdminRole::SuperAdmin) {
            return redirect('/admin/users')
                ->with('flash', ['error' => 'Only a Super Admin can manage Super Admin accounts.']);
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function assignableRoles(Request $request): array
    {
        $roles = AdminRole::options();

        if (! $this->permissions->currentAdmin($request)?->isSuperAdmin()) {
            unset($roles[AdminRole::SuperAdmin]);
        }

        return $roles;
    }

    /**
     * @param  list<int|string>  $satelliteIds
     */
    private function syncSatellites(AdminUser $admin, string $role, array $satelliteIds): void
    {
        if ($role !== AdminRole::SatelliteAdministrator) {
            $admin->satellites()->sync([]);

            return;
        }

        $ids = array_values(array_unique(array_map('intval', $satelliteIds)));
        $admin->satellites()->sync($ids);
    }

    /**
     * @return array{unreadMessages: int, pendingMembers: int, pendingOrders: int, pendingGallery: int}
     */
    private function emptyCounts(): array
    {
        return [
            'unreadMessages' => 0,
            'pendingMembers' => 0,
            'pendingOrders' => 0,
            'pendingGallery' => 0,
        ];
    }
}
