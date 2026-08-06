<?php

namespace Tests\Feature;

use App\Models\MembershipPlan;
use App\Models\Promotion;
use App\Services\PromotionService;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionServiceTest extends TestCase
{
    use RefreshDatabase;

    private PromotionService $promotionService;

    private MembershipPlan $annualPlan;

    private MembershipPlan $quarterlyPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SatelliteSeeder::class);
        $this->seed(MembershipPlanSeeder::class);

        $this->promotionService = app(PromotionService::class);
        $this->annualPlan = MembershipPlan::query()->where('billing_cycle', 'annual')->firstOrFail();
        $this->quarterlyPlan = MembershipPlan::query()->where('billing_cycle', 'quarterly')->firstOrFail();
    }

    private function makePromotion(array $overrides = []): Promotion
    {
        return Promotion::query()->create(array_merge([
            'name' => 'Test Promotion',
            'plan_id' => $this->annualPlan->id,
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addMonth()->toDateString(),
            'is_active' => true,
        ], $overrides));
    }

    public function test_full_price_when_no_promotion_is_active(): void
    {
        $pricing = $this->promotionService->priceForPlan($this->annualPlan);

        $this->assertSame(1000.0, $pricing['amount']);
        $this->assertNull($pricing['promotionId']);
        $this->assertSame(0.0, $pricing['discountAmount']);
    }

    public function test_percentage_promotion_discounts_the_price(): void
    {
        $promotion = $this->makePromotion(['discount_type' => 'percentage', 'discount_value' => 10]);

        $pricing = $this->promotionService->priceForPlan($this->annualPlan);

        $this->assertSame(900.0, $pricing['amount']);
        $this->assertSame($promotion->id, $pricing['promotionId']);
        $this->assertSame(100.0, $pricing['discountAmount']);
    }

    public function test_fixed_promotion_discounts_the_price(): void
    {
        $this->makePromotion(['discount_type' => 'fixed', 'discount_value' => 150]);

        $pricing = $this->promotionService->priceForPlan($this->annualPlan);

        $this->assertSame(850.0, $pricing['amount']);
        $this->assertSame(150.0, $pricing['discountAmount']);
    }

    public function test_fixed_discount_larger_than_price_is_clamped_to_zero(): void
    {
        $this->makePromotion(['discount_type' => 'fixed', 'discount_value' => 5000]);

        $pricing = $this->promotionService->priceForPlan($this->annualPlan);

        $this->assertSame(0.0, $pricing['amount']);
        $this->assertSame(1000.0, $pricing['discountAmount']);
    }

    public function test_expired_promotion_is_ignored(): void
    {
        $this->makePromotion(['starts_at' => now()->subMonth()->toDateString(), 'ends_at' => now()->subDay()->toDateString()]);

        $pricing = $this->promotionService->priceForPlan($this->annualPlan);

        $this->assertSame(1000.0, $pricing['amount']);
        $this->assertNull($pricing['promotionId']);
    }

    public function test_upcoming_promotion_is_ignored(): void
    {
        $this->makePromotion(['starts_at' => now()->addDay()->toDateString(), 'ends_at' => now()->addMonth()->toDateString()]);

        $pricing = $this->promotionService->priceForPlan($this->annualPlan);

        $this->assertSame(1000.0, $pricing['amount']);
        $this->assertNull($pricing['promotionId']);
    }

    public function test_disabled_promotion_is_ignored_even_inside_its_date_window(): void
    {
        $this->makePromotion(['is_active' => false]);

        $pricing = $this->promotionService->priceForPlan($this->annualPlan);

        $this->assertSame(1000.0, $pricing['amount']);
        $this->assertNull($pricing['promotionId']);
    }

    public function test_promotion_scoped_to_a_different_plan_does_not_apply(): void
    {
        $this->makePromotion(['plan_id' => $this->quarterlyPlan->id]);

        $pricing = $this->promotionService->priceForPlan($this->annualPlan);

        $this->assertSame(1000.0, $pricing['amount']);
        $this->assertNull($pricing['promotionId']);
    }

    public function test_plan_specific_promotion_wins_over_sitewide_promotion(): void
    {
        $this->makePromotion(['plan_id' => null, 'discount_type' => 'percentage', 'discount_value' => 5, 'name' => 'Sitewide']);
        $specific = $this->makePromotion(['plan_id' => $this->annualPlan->id, 'discount_type' => 'percentage', 'discount_value' => 10, 'name' => 'Annual-specific']);

        $pricing = $this->promotionService->priceForPlan($this->annualPlan);

        $this->assertSame($specific->id, $pricing['promotionId']);
        $this->assertSame(900.0, $pricing['amount']);
    }

    public function test_sitewide_promotion_applies_when_no_plan_specific_one_exists(): void
    {
        $sitewide = $this->makePromotion(['plan_id' => null, 'discount_type' => 'percentage', 'discount_value' => 5]);

        $pricing = $this->promotionService->priceForPlan($this->quarterlyPlan);

        $this->assertSame($sitewide->id, $pricing['promotionId']);
        $this->assertSame(237.5, $pricing['amount']);
    }
}
