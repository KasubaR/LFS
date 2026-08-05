<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Concerns\ProvidesAuthViews;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\MemberOnboardingService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthenticatedSessionController extends Controller
{
    use ProvidesAuthViews;

    public function __construct(
        private readonly MemberOnboardingService $onboardingService,
    ) {}

    public function create(): View
    {
        return view('pages.auth.login', $this->signupViewData([
            'title' => 'Sign In',
            'description' => 'Sign in to your LFS member account.',
            'page' => 'auth',
        ]));
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $credentials = $request->only('email', 'password');
        $credentials['email'] = strtolower($credentials['email']);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        $user = Auth::user();

        // Bulk-imported members share one org-wide temp password (config/member_import.php)
        // that's only valid for a limited window. Once that window has passed without the
        // member setting their own password, reject the login even though the hash still
        // matches — don't leave a session behind for it.
        if ($user->must_change_password
            && $user->temp_password_expires_at !== null
            && now()->greaterThan($user->temp_password_expires_at)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => 'Your temporary password has expired. Please use "Forgot password" to set a new one.',
            ]);
        }

        $request->session()->regenerate();

        if ($user->first_login === null) {
            $user->forceFill(['first_login' => now()])->save();
        }

        return redirect()->intended($this->onboardingService->resolveNextRoute($user));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
