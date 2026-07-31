<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Concerns\ProvidesAuthViews;
use App\Http\Controllers\Controller;
use App\Services\MemberOnboardingService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    use ProvidesAuthViews;

    public function __construct(
        private readonly MemberOnboardingService $onboardingService,
    ) {}

    public function notice(): View|RedirectResponse
    {
        $user = request()->user();

        if ($user?->hasVerifiedEmail()) {
            return redirect($this->onboardingService->resolveNextRoute($user));
        }

        return view('pages.auth.verify-email', $this->signupViewData([
            'title' => 'Verify Email',
            'description' => 'Verify your email address to continue your LFS membership.',
            'page' => 'auth',
        ]));
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasVerifiedEmail()) {
            if ($user->markEmailAsVerified()) {
                event(new Verified($user));
            }
        }

        return redirect($this->onboardingService->resolveNextRoute($user))
            ->with('auth_status', 'Your email has been verified.');
    }

    public function send(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect($this->onboardingService->resolveNextRoute($user));
        }

        $user->sendEmailVerificationNotification();

        return back()->with('auth_status', 'A new verification link has been sent to your email address.');
    }
}
