<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminRole;
use App\Enums\ElectionAuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminLoginRequest;
use App\Models\AdminUser;
use App\Services\Election\ElectionAuditService;
use App\Support\Totp;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(
        private readonly ElectionAuditService $electionAudit,
    ) {}

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
            $this->electionAudit->record(
                null,
                ElectionAuditAction::AdminLoginFailed,
                'admin',
                null,
                ['email' => $email],
                $request->ip(),
            );

            return redirect('/admin/'.config('admin.login_slug'))
                ->withInput($request->only('email'))
                ->with('admin_login_error', 'Invalid email or password. Please try again.');
        }

        if ($admin->requiresTotp()) {
            if (! $admin->hasTotpEnabled()) {
                $request->session()->put('admin_totp_setup_id', $admin->id);
                if (! $admin->totp_secret) {
                    $admin->forceFill(['totp_secret' => Totp::generateSecret()])->save();
                }

                return redirect('/admin/'.config('admin.login_slug').'/2fa/setup');
            }

            $request->session()->put('admin_totp_pending_id', $admin->id);

            return redirect('/admin/'.config('admin.login_slug').'/2fa');
        }

        return $this->completeLogin($request, $admin);
    }

    public function showTotpChallenge(): View|RedirectResponse
    {
        if (! session('admin_totp_pending_id')) {
            return redirect('/admin/'.config('admin.login_slug'));
        }

        return view('admin.auth.totp-challenge', [
            'pageTitle' => 'Two-factor authentication',
            'activePage' => '',
            'authPage' => true,
            'error' => session()->pull('admin_login_error'),
        ]);
    }

    public function verifyTotp(Request $request): RedirectResponse
    {
        $adminId = (int) session('admin_totp_pending_id');
        $admin = AdminUser::query()->find($adminId);
        if (! $admin) {
            return redirect('/admin/'.config('admin.login_slug'));
        }

        $code = (string) $request->input('code', '');
        if (! Totp::verify((string) $admin->totp_secret, $code)) {
            $this->electionAudit->record(
                null,
                ElectionAuditAction::AdminLoginFailed,
                'admin',
                (string) $admin->id,
                ['reason' => 'totp_failed'],
                $request->ip(),
            );

            return back()->with('admin_login_error', 'Invalid authentication code.');
        }

        $request->session()->forget('admin_totp_pending_id');

        return $this->completeLogin($request, $admin);
    }

    public function showTotpSetup(): View|RedirectResponse
    {
        $adminId = (int) session('admin_totp_setup_id');
        $admin = AdminUser::query()->find($adminId);
        if (! $admin) {
            return redirect('/admin/'.config('admin.login_slug'));
        }

        if (! $admin->totp_secret) {
            $admin->forceFill(['totp_secret' => Totp::generateSecret()])->save();
        }

        return view('admin.auth.totp-setup', [
            'pageTitle' => 'Set up two-factor authentication',
            'activePage' => '',
            'authPage' => true,
            'secret' => $admin->totp_secret,
            'uri' => Totp::provisioningUri((string) $admin->totp_secret, $admin->email),
            'error' => session()->pull('admin_login_error'),
        ]);
    }

    public function confirmTotpSetup(Request $request): RedirectResponse
    {
        $adminId = (int) session('admin_totp_setup_id');
        $admin = AdminUser::query()->find($adminId);
        if (! $admin || ! $admin->totp_secret) {
            return redirect('/admin/'.config('admin.login_slug'));
        }

        $code = (string) $request->input('code', '');
        if (! Totp::verify((string) $admin->totp_secret, $code)) {
            return back()->with('admin_login_error', 'Invalid authentication code. Try again.');
        }

        $admin->forceFill(['totp_confirmed_at' => now()])->save();
        $request->session()->forget('admin_totp_setup_id');

        return $this->completeLogin($request, $admin);
    }

    public function logout(): RedirectResponse
    {
        session()->forget([
            config('admin.session_auth_key'),
            config('admin.session_active_key'),
            config('admin.session_user_key'),
            'admin_login_error',
            'admin_totp_pending_id',
            'admin_totp_setup_id',
        ]);
        session()->invalidate();
        session()->regenerateToken();

        return redirect('/');
    }

    private function completeLogin(Request $request, AdminUser $admin): RedirectResponse
    {
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
