<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Concerns\ProvidesAuthViews;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;

class PasswordResetLinkController extends Controller
{
    use ProvidesAuthViews;

    public function create(): View
    {
        return view('pages.auth.forgot-password', $this->authViewData([
            'title' => 'Forgot Password',
            'description' => 'Reset your LFS member account password.',
            'page' => 'auth',
        ]));
    }

    public function store(ForgotPasswordRequest $request): RedirectResponse
    {
        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status !== Password::RESET_LINK_SENT) {
            return back()
                ->withInput()
                ->withErrors(['email' => __($status)]);
        }

        return back()->with('auth_status', __($status));
    }
}
