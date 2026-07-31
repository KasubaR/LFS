<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminLoginRequest;
use App\Models\AdminUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        if ($this->isAuthenticated()) {
            return redirect('/admin/dashboard');
        }

        return view('admin.auth.login', [
            'pageTitle' => 'Admin Login',
            'activePage' => '',
            'authPage' => true,
            'error' => session()->pull('admin_login_error'),
            'email' => old('email', ''),
        ]);
    }

    public function login(AdminLoginRequest $request): RedirectResponse
    {
        $email = strtolower(trim((string) $request->input('email')));
        $password = (string) $request->input('password', '');

        $admin = AdminUser::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if ($admin === null || ! $admin->is_active || ! Hash::check($password, $admin->password)) {
            return redirect('/admin/'.config('admin.login_slug'))
                ->withInput($request->only('email'))
                ->with('admin_login_error', 'Invalid email or password. Please try again.');
        }

        $request->session()->regenerate();
        $request->session()->put([
            config('admin.session_auth_key') => true,
            config('admin.session_active_key') => time(),
            config('admin.session_user_key') => $admin->id,
        ]);
        $request->session()->forget('admin_login_error');

        $admin->forceFill(['last_login_at' => now()])->save();

        return redirect('/admin/dashboard');
    }

    public function logout(): RedirectResponse
    {
        session()->forget([
            config('admin.session_auth_key'),
            config('admin.session_active_key'),
            config('admin.session_user_key'),
            'admin_login_error',
        ]);
        session()->invalidate();
        session()->regenerateToken();

        return redirect('/');
    }

    private function isAuthenticated(): bool
    {
        $authKey = config('admin.session_auth_key');
        if (! session($authKey) || ! session(config('admin.session_user_key'))) {
            return false;
        }

        $lastActive = (int) session(config('admin.session_active_key'), 0);
        $timeout = (int) config('admin.session_timeout', 1800);

        if ((time() - $lastActive) > $timeout) {
            session()->forget([
                $authKey,
                config('admin.session_active_key'),
                config('admin.session_user_key'),
            ]);

            return false;
        }

        return true;
    }
}
