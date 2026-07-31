<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Concerns\ProvidesAuthViews;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class NewPasswordController extends Controller
{
    use ProvidesAuthViews;

    public function create(string $token): View
    {
        return view('pages.auth.reset-password', $this->authViewData([
            'title' => 'Reset Password',
            'description' => 'Choose a new password for your LFS account.',
            'page' => 'auth',
            'token' => $token,
            'email' => request()->query('email', ''),
        ]));
    }

    public function store(ResetPasswordRequest $request): RedirectResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()
                ->withInput()
                ->with('auth_errors', ['email' => __($status)]);
        }

        return redirect('/login')->with('auth_status', __($status));
    }
}
