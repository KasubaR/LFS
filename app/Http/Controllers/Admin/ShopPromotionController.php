<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PromotionDiscountType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreShopPromotionRequest;
use App\Services\AdminPermissionService;
use App\Services\ProductCategoryService;
use App\Services\ShopPromotionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class ShopPromotionController extends Controller
{
    public function __construct(
        private readonly ShopPromotionService $promotions,
        private readonly ProductCategoryService $categories,
        private readonly AdminPermissionService $permissions,
    ) {}

    public function index(): View
    {
        return view('admin.shop-promotions.index', [
            'pageTitle' => 'Shop promotions',
            'activePage' => 'shop-promotions',
            'promotions' => $this->promotions->getAll(),
            'categoryNames' => $this->categoryOptions(),
            'breadcrumbs' => [
                ['label' => 'Admin', 'url' => '/admin'],
                ['label' => 'Shop promotions'],
            ],
        ]);
    }

    public function create(): View
    {
        return $this->form(null);
    }

    public function store(StoreShopPromotionRequest $request): RedirectResponse
    {
        $this->promotions->create($this->payload($request) + [
            'createdBy' => $this->permissions->currentAdmin($request)?->id,
        ]);

        return redirect('/admin/shop-promotions')->with('flash', ['success' => 'Shop promotion created.']);
    }

    public function edit(int $id): View|RedirectResponse
    {
        $promotion = $this->promotions->findById($id);

        return $promotion ? $this->form($promotion) : redirect('/admin/shop-promotions');
    }

    public function update(StoreShopPromotionRequest $request, int $id): RedirectResponse
    {
        if (! $this->promotions->update($id, $this->payload($request))) {
            return redirect('/admin/shop-promotions');
        }

        return redirect('/admin/shop-promotions')->with('flash', ['success' => 'Shop promotion updated.']);
    }

    public function destroy(int $id): RedirectResponse
    {
        $this->promotions->delete($id);

        return redirect('/admin/shop-promotions')->with('flash', ['success' => 'Shop promotion deleted.']);
    }

    /**
     * @param  array<string, mixed>|null  $promotion
     */
    private function form(?array $promotion): View
    {
        $isEdit = $promotion !== null;

        return view('admin.shop-promotions.form', [
            'pageTitle' => $isEdit ? 'Edit shop promotion' : 'New shop promotion',
            'activePage' => 'shop-promotions',
            'promotion' => $promotion,
            'isEdit' => $isEdit,
            'categoryNames' => $this->categoryOptions(),
            'discountTypes' => PromotionDiscountType::ALL,
            'breadcrumbs' => [
                ['label' => 'Admin', 'url' => '/admin'],
                ['label' => 'Shop promotions', 'url' => '/admin/shop-promotions'],
                ['label' => $isEdit ? $promotion['name'] : 'New'],
            ],
        ]);
    }

    /**
     * @return array<string, string> slug => name
     */
    private function categoryOptions(): array
    {
        return $this->categories->filterOptions(true);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(StoreShopPromotionRequest $request): array
    {
        $v = $request->validated();

        return [
            'name' => $v['name'],
            'code' => $v['code'] ?? null,
            'discountType' => $v['discount_type'] ?? PromotionDiscountType::Percentage,
            'discountValue' => $v['discount_value'],
            'category' => $v['category'] ?? null,
            'membersOnly' => $request->boolean('members_only'),
            'minOrderAmount' => $v['min_order_amount'] ?? null,
            'startsAt' => $v['starts_at'],
            'endsAt' => $v['ends_at'],
            'isActive' => $request->boolean('is_active'),
            'notes' => $v['notes'] ?? null,
        ];
    }
}
