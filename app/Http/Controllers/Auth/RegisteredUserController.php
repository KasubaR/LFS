<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Concerns\ProvidesAuthViews;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

class RegisteredUserController extends Controller
{
    use ProvidesAuthViews;

    public function create(): View
    {
        return view('pages.auth.create-account', $this->authViewData([
            'title' => 'Create Account',
            'description' => 'Join Lusaka Fitness Squad — create your account to get started.',
            'page' => 'auth',
            'bodyClass' => 'page-no-hero page-no-nav',
            'hideNavbar' => true,
        ]));
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $user = DB::transaction(function () use ($validated) {
                return User::query()->create([
                    'name' => $validated['name'],
                    'email' => strtolower($validated['email']),
                    'password' => $validated['password'],
                    'phone' => $validated['phone'],
                    'gender' => $validated['gender'],
                    'nationality' => $validated['nationality'],
                    't_shirt_size' => $validated['t_shirt_size'],
                    'town' => $validated['town'],
                    'registered_at' => now(),
                    'must_change_password' => false,
                    'force_email_verification' => true,
                ]);
            });
        } catch (Throwable) {
            return redirect('/create-account')
                ->withInput()
                ->withErrors(['_general' => 'Sorry, we could not create your account. Please try again.']);
        }

        event(new Registered($user));

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('verification.notice')
            ->with('auth_status', 'Account created! Please verify your email to continue.');
    }
}
