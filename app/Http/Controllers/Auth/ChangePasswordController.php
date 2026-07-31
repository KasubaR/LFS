<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Concerns\ProvidesAuthViews;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Services\MemberOnboardingService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class ChangePasswordController extends Controller
{
    use ProvidesAuthViews;

    public function __construct(
        private readonly MemberOnboardingService $onboardingService,
    ) {}

    public function create(): View|RedirectResponse
    {
        $user = Auth::user();

        if (! $user->must_change_password) {
            return redirect($this->onboardingService->resolveNextRoute($user));
        }

        return view('pages.auth.change-password', $this->signupViewData([
            'title' => 'Change Password',
            'description' => 'Set a new password for your LFS member account.',
            'page' => 'auth',
        ]));
    }

    public function store(ChangePasswordRequest $request): RedirectResponse
    {
        $user = Auth::user();

        if (! $user->must_change_password) {
            return redirect($this->onboardingService->resolveNextRoute($user));
        }

        $user->forceFill([
            'password' => Hash::make($request->validated('password')),
            'must_change_password' => false,
        ])->save();

        return redirect()->route('account')
            ->with('auth_status', 'Your password has been updated.');
    }
}
