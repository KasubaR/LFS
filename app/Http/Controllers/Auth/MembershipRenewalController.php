<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\CodeException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RenewMembershipRequest;
use App\Services\MembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class MembershipRenewalController extends Controller
{
    public function __construct(
        private readonly MembershipService $membershipService,
    ) {}

    public function store(RenewMembershipRequest $request): RedirectResponse
    {
        $user = Auth::user();

        try {
            $this->membershipService->startRenewal((int) $user->id, (int) $request->validated('plan_id'));
        } catch (CodeException $e) {
            return redirect()->route('account')
                ->withErrors(['_general' => $e->getMessage()]);
        }

        return redirect()->route('account')
            ->with('auth_status', 'Renewal started. Continue to payment to reactivate your membership.');
    }
}
