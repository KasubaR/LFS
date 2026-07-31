<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Concerns\ProvidesAuthViews;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\MembershipApplicationRequest;
use App\Services\MembershipPlanService;
use App\Services\MembershipService;
use App\Services\SatelliteService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

class MembershipApplicationController extends Controller
{
    use ProvidesAuthViews;

    public function __construct(
        private readonly SatelliteService $satelliteService,
        private readonly MembershipPlanService $planService,
        private readonly MembershipService $membershipService,
    ) {}

    public function create(): View|RedirectResponse
    {
        $user = Auth::user();

        if ($this->membershipService->userHasOpenMembership((int) $user->id)) {
            return redirect()->route('account');
        }

        return view('pages.auth.choose-membership', $this->signupViewData([
            'title' => 'Choose Membership',
            'description' => 'Select your nearest satellite and LFS membership plan.',
            'page' => 'auth',
            'satellites' => $this->satelliteService->getActiveSatellites(),
            'plans' => $this->planService->getActivePlans(),
        ]));
    }

    public function store(MembershipApplicationRequest $request): RedirectResponse
    {
        $user = Auth::user();
        $validated = $request->validated();

        if ($this->membershipService->userHasOpenMembership((int) $user->id)) {
            return redirect()->route('account');
        }

        try {
            DB::transaction(function () use ($user, $validated): void {
                $user->update(['satellite_id' => $validated['satellite_id']]);

                $this->membershipService->createApplication(
                    (int) $user->id,
                    (int) $validated['plan_id'],
                );
            });
        } catch (Throwable) {
            return redirect()->route('membership.apply')
                ->withInput()
                ->withErrors(['_general' => 'Sorry, we could not save your membership choice. Please try again.']);
        }

        return redirect()->route('account')
            ->with('auth_status', 'Membership plan selected. You can continue to payment from your account.');
    }
}
