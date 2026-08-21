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

    public function create(): View
    {
        return view('admin.users.form', [
            'pageTitle' => 'Create Admin User',
            'activePage' => 'users',
            'admin' => null,
            'roles' => AdminRole::options(),
            'satelliteRole' => AdminRole::SatelliteAdministrator,
            'satellites' => Satellite::query()->where('is_active', true)->orderBy('name')->get(),
            'selectedSatelliteIds' => [],
            'counts' => $this->emptyCounts(),
        ]);
    }

    public function store(StoreAdminUserRequest $request): RedirectResponse
    {
        $data = $request->validated();

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

    public function edit(int $id): View
    {
        $admin = AdminUser::query()->with('satellites')->findOrFail($id);

        return view('admin.users.form', [
            'pageTitle' => 'Edit Admin User',
            'activePage' => 'users',
            'admin' => $admin,
            'roles' => AdminRole::options(),
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

        if ($current && (int) $current->id === (int) $admin->id) {
            return redirect('/admin/users')
                ->with('flash', ['error' => 'You cannot deactivate your own account.']);
        }

        $admin->forceFill(['is_active' => false])->save();

        return redirect('/admin/users')
            ->with('flash', ['success' => 'Admin user deactivated.']);
    }

    public function activate(int $id): RedirectResponse
    {
        $admin = AdminUser::query()->findOrFail($id);
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
    public function resetTotp(int $id): RedirectResponse
    {
        $admin = AdminUser::query()->findOrFail($id);
        $admin->forceFill(['totp_secret' => null, 'totp_confirmed_at' => null])->save();

        return redirect('/admin/users')
            ->with('flash', ['success' => "Two-factor authentication reset for {$admin->name}. They will set it up again on next login."]);
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
