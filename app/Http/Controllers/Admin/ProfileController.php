<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminProfileRequest;
use App\Services\AdminPermissionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    public function __construct(
        private readonly AdminPermissionService $permissions,
    ) {}

    public function edit(): View
    {
        $admin = $this->permissions->currentAdmin();
        abort_if($admin === null, 403);

        return view('admin.profile.edit', [
            'pageTitle' => 'My Profile',
            'activePage' => '',
            'admin' => $admin,
            'counts' => [
                'unreadMessages' => 0,
                'pendingMembers' => 0,
                'pendingOrders' => 0,
                'pendingGallery' => 0,
            ],
        ]);
    }

    public function update(UpdateAdminProfileRequest $request): RedirectResponse
    {
        $admin = $this->permissions->currentAdmin($request);
        abort_if($admin === null, 403);

        $data = $request->validated();

        $admin->name = $data['name'];

        if (! empty($data['password'])) {
            if (! Hash::check($data['current_password'] ?? '', $admin->password)) {
                return redirect('/admin/profile')
                    ->withInput($request->except(['password', 'password_confirmation', 'current_password']))
                    ->withErrors(['current_password' => 'Current password is incorrect.']);
            }

            $admin->password = $data['password'];
        }

        $admin->save();

        return redirect('/admin/profile')
            ->with('flash', ['success' => 'Profile updated.']);
    }
}
