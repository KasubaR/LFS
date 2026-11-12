<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PromotionDiscountType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePromotionRequest;
use App\Services\AdminPermissionService;
use App\Services\MembershipPlanService;
use App\Services\MembershipYearService;
use App\Services\PromotionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PromotionController extends Controller
{
    public function __construct(
        private readonly PromotionService $promotionService,
        private readonly MembershipPlanService $planService,
        private readonly AdminPermissionService $permissions,
        private readonly MembershipYearService $yearService,
    ) {}

    public function index(): View
    {
        return view('admin.promotions.index', [
            'pageTitle' => 'Promotions',
            'activePage' => 'promotions',
            'promotions' => $this->promotionService->getAll(),
            'breadcrumbs' => [
                ['label' => 'Admin', 'url' => '/admin'],
                ['label' => 'Promotions'],
            ],
        ]);
    }

    public function membershipYear(): View
    {
        $opensOn = $this->yearService->opensOn();

        return view('admin.promotions.membership-year', [
            'pageTitle' => 'Next-year registration',
            'activePage' => 'promotions',
            'opensOn' => $opensOn?->toDateString(),
            'targetYear' => $opensOn ? $opensOn->year + 1 : now()->year + 1,
            'isOpenNow' => $this->yearService->isNextYearOpen(),
            'breadcrumbs' => [
                ['label' => 'Admin', 'url' => '/admin'],
                ['label' => 'Promotions', 'url' => '/admin/promotions'],
                ['label' => 'Next-year registration'],
            ],
        ]);
    }

    public function saveMembershipYear(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'next_year_opens_on' => ['nullable', 'date', 'after_or_equal:'.now()->year.'-07-01', 'before_or_equal:'.now()->year.'-12-31'],
        ], [
            'next_year_opens_on.after_or_equal' => 'Choose a date between 1 July and 31 December of this year.',
            'next_year_opens_on.before_or_equal' => 'Choose a date between 1 July and 31 December of this year.',
        ]);

        $date = $validated['next_year_opens_on'] ?? null;
        $this->yearService->setOpensOn($date ?: null, $this->permissions->currentAdmin($request)?->id);

        return redirect('/admin/promotions/membership-year')->with('flash', [
            'success' => $date ? 'Next-year registration opens on '.$date.'.' : 'Next-year registration will open on 1 January.',
        ]);
    }

    public function create(): View
    {
        return view('admin.promotions.form', [
            'pageTitle' => 'New Promotion',
            'activePage' => 'promotions',
            'promotion' => null,
            'plans' => $this->planService->getActivePlans(),
            'discountTypes' => PromotionDiscountType::ALL,
            'isEdit' => false,
            'breadcrumbs' => [
                ['label' => 'Admin', 'url' => '/admin'],
                ['label' => 'Promotions', 'url' => '/admin/promotions'],
                ['label' => 'New Promotion'],
            ],
        ]);
    }

    public function store(StorePromotionRequest $request): RedirectResponse
    {
        $this->promotionService->create($this->payload($request));

        return redirect('/admin/promotions')->with('flash', ['success' => 'Promotion created.']);
    }

    public function edit(int $id): View|RedirectResponse
    {
        $promotion = $this->promotionService->findById($id);
        if (! $promotion) {
            return redirect('/admin/promotions');
        }

        return view('admin.promotions.form', [
            'pageTitle' => 'Edit Promotion',
            'activePage' => 'promotions',
            'promotion' => $promotion,
            'plans' => $this->planService->getActivePlans(),
            'discountTypes' => PromotionDiscountType::ALL,
            'isEdit' => true,
            'breadcrumbs' => [
                ['label' => 'Admin', 'url' => '/admin'],
                ['label' => 'Promotions', 'url' => '/admin/promotions'],
                ['label' => $promotion['name']],
            ],
        ]);
    }

    public function update(StorePromotionRequest $request, int $id): RedirectResponse
    {
        $updated = $this->promotionService->update($id, $this->payload($request));
        if (! $updated) {
            return redirect('/admin/promotions');
        }

        return redirect('/admin/promotions')->with('flash', ['success' => 'Promotion updated.']);
    }

    public function destroy(int $id): RedirectResponse
    {
        $this->promotionService->delete($id);

        return redirect('/admin/promotions')->with('flash', ['success' => 'Promotion deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(StorePromotionRequest $request): array
    {
        $validated = $request->validated();

        return [
            'name' => $validated['name'],
            'planId' => $validated['plan_id'] ?? null,
            'membershipYear' => $validated['membership_year'] ?? null,
            'appliesTo' => $validated['applies_to'] ?? 'all',
            'discountType' => $validated['discount_type'],
            'discountValue' => $validated['discount_value'],
            'startsAt' => $validated['starts_at'],
            'endsAt' => $validated['ends_at'],
            'isActive' => $request->boolean('is_active'),
            'notes' => $validated['notes'] ?? null,
            'createdBy' => $this->permissions->currentAdmin($request)?->id,
        ];
    }
}
