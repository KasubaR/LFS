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

        $request->session()->regenerate();

        $user = Auth::user();
        if ($user && $user->first_login === null) {
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
