<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Concerns\ProvidesAuthViews;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\MemberOnboardingService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

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

    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = User::query()->findOrFail($id);

        if (! hash_equals(sha1($user->getEmailForVerification()), (string) $hash)) {
            abort(403, 'Invalid verification link.');
        }

        if (! $user->hasVerifiedEmail()) {
            if ($user->markEmailAsVerified()) {
                event(new Verified($user));
            }
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect($this->onboardingService->resolveNextRoute($user->fresh()))
            ->with('auth_status', 'Your email has been verified.');
    }

    public function send(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect($this->onboardingService->resolveNextRoute($user));
        }

        try {
            $user->sendEmailVerificationNotification();
        } catch (Throwable $e) {
            report($e);

            return back()->with('auth_status', 'We could not send the verification email right now. Please try again in a few minutes.');
        }

        return back()->with('auth_status', 'A new verification link has been sent to your email address.');
    }
}
