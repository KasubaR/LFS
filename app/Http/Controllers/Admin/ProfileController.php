<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminProfileRequest;
use App\Services\AdminPermissionService;
use App\Support\Totp;
use Illuminate\Http\Request;
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

    public function enableTotp(Request $request): RedirectResponse
    {
        $admin = $this->permissions->currentAdmin($request);
        abort_if($admin === null, 403);

        if ($admin->hasTotpEnabled()) {
            return redirect('/admin/profile');
        }

        $admin->forceFill(['totp_secret' => Totp::generateSecret()])->save();
        $request->session()->put('admin_totp_setup_id', $admin->id);

        return redirect('/admin/'.config('admin.login_slug').'/2fa/setup');
    }

    public function disableTotp(Request $request): RedirectResponse
    {
        $admin = $this->permissions->currentAdmin($request);
        abort_if($admin === null, 403);

        if (! Hash::check((string) $request->input('password', ''), $admin->password)) {
            return redirect('/admin/profile')
                ->withErrors(['totp_password' => 'Password is incorrect.']);
        }

        $admin->forceFill(['totp_secret' => null, 'totp_confirmed_at' => null])->save();

        return redirect('/admin/profile')
            ->with('flash', ['success' => 'Two-factor authentication turned off.']);
    }
}
