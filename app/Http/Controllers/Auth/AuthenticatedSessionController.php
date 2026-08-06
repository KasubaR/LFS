<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Concerns\ProvidesAuthViews;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Membership;
use App\Models\User;
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
        $login = trim((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');

        // Members can sign in with either their email or their LFS member number —
        // resolve whichever they typed to a concrete account first, then attempt
        // by that account's id so the password check goes through the normal
        // Auth::attempt/hashing path either way.
        $user = $this->resolveLoginUser($login);

        if ($user === null || ! Auth::attempt(['id' => $user->id, 'password' => $password], $request->boolean('remember'))) {
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

            // Imported members never get a verification email until they
            // actually show up and log in — see MemberImportService — so the
            // signed link is fresh relative to now rather than the import
            // batch, which could've run days or weeks earlier.
            if (! $user->hasVerifiedEmail()) {
                $user->sendEmailVerificationNotification();
            }
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

    /**
     * Resolves the "email or member number" login field to a concrete User.
     * A value containing "@" is treated as an email; anything else is looked
     * up as an LFS membership number. Renewals reuse the same membership_number
     * on a new row (see MembershipService::startRenewal), so a valid number
     * should map to exactly one user_id — if it somehow maps to more than one,
     * treat it as unresolved rather than guessing which account to log into.
     */
    private function resolveLoginUser(string $login): ?User
    {
        if ($login === '') {
            return null;
        }

        if (str_contains($login, '@')) {
            return User::query()->where('email', strtolower($login))->first();
        }

        $userIds = Membership::query()
            ->whereRaw('UPPER(membership_number) = ?', [strtoupper($login)])
            ->distinct()
            ->pluck('user_id');

        if ($userIds->count() !== 1) {
            return null;
        }

        return User::query()->find($userIds->first());
    }
}
