<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\User;

class MemberOnboardingService
{
    public function __construct(
        private readonly MembershipService $membershipService,
    ) {}

    public function resolveNextRoute(User $user): string
    {
        if (! $user->hasVerifiedEmail()) {
            return route('verification.notice', absolute: false);
        }

        if ($user->must_change_password) {
            return route('password.change', absolute: false);
        }

        if (! Membership::query()->where('user_id', $user->id)->exists()) {
            return route('membership.apply', absolute: false);
        }

        return route('account', absolute: false);
    }
}
