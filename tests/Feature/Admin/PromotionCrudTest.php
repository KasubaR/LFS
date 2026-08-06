<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Models\MembershipPlan;
use App\Models\Promotion;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class PromotionCrudTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SatelliteSeeder::class);
        $this->seed(MembershipPlanSeeder::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalPayload(array $overrides = []): array
    {
        $plan = MembershipPlan::query()->where('billing_cycle', 'annual')->firstOrFail();

        return array_merge([
            'name' => 'Early Bird Annual',
            'plan_id' => $plan->id,
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addMonth()->toDateString(),
            'is_active' => '1',
        ], $overrides);
    }

    public function test_super_admin_can_create_promotion_and_created_by_is_recorded(): void
    {
        $admin = $this->makeAdminUser();

        $response = $this->actingAsAdmin($admin)->post('/admin/promotions/create', $this->minimalPayload());

        $response->assertRedirect('/admin/promotions');
        $this->assertDatabaseCount('promotions', 1);

        $promotion = Promotion::query()->first();
        $this->assertSame('Early Bird Annual', $promotion->name);
        $this->assertSame(10.0, (float) $promotion->discount_value);
        $this->assertSame($admin->id, $promotion->created_by);
    }

    public function test_editing_a_promotion_does_not_overwrite_created_by(): void
    {
        $creator = $this->makeAdminUser(['name' => 'Creator']);
        $editor = $this->makeAdminUser(['name' => 'Editor']);

        $this->actingAsAdmin($creator)->post('/admin/promotions/create', $this->minimalPayload());
        $promotion = Promotion::query()->firstOrFail();

        $this->actingAsAdmin($editor)->post("/admin/promotions/{$promotion->id}/edit", $this->minimalPayload([
            'name' => 'Early Bird Annual (updated)',
        ]));

        $promotion->refresh();
        $this->assertSame('Early Bird Annual (updated)', $promotion->name);
        $this->assertSame($creator->id, $promotion->created_by);
    }

    public function test_read_only_auditor_cannot_create_promotion(): void
    {
        $auditor = $this->makeAdminUser(['role' => AdminRole::ReadOnlyAuditor]);

        $response = $this->actingAsAdmin($auditor)->post('/admin/promotions/create', $this->minimalPayload());

        $response->assertForbidden();
        $this->assertDatabaseCount('promotions', 0);
    }

    public function test_read_only_auditor_can_view_but_not_delete(): void
    {
        $auditor = $this->makeAdminUser(['role' => AdminRole::ReadOnlyAuditor]);
        $plan = MembershipPlan::query()->where('billing_cycle', 'annual')->firstOrFail();
        $promotion = Promotion::query()->create([
            'name' => 'Existing Promo',
            'plan_id' => $plan->id,
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addMonth()->toDateString(),
            'is_active' => true,
        ]);

        $this->actingAsAdmin($auditor)->get('/admin/promotions')->assertOk();
        $this->actingAsAdmin($auditor)->get('/admin/promotions/create')->assertOk();

        $this->actingAsAdmin($auditor)
            ->post("/admin/promotions/{$promotion->id}/delete")
            ->assertForbidden();
        $this->assertDatabaseCount('promotions', 1);
    }

    public function test_end_date_before_start_date_is_rejected(): void
    {
        $response = $this->actingAsAdmin()->post('/admin/promotions/create', $this->minimalPayload([
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->subDay()->toDateString(),
        ]));

        $response->assertRedirect();
        $response->assertSessionHasErrors('ends_at');
        $this->assertDatabaseCount('promotions', 0);
    }

    public function test_percentage_discount_over_100_is_rejected(): void
    {
        $response = $this->actingAsAdmin()->post('/admin/promotions/create', $this->minimalPayload([
            'discount_type' => 'percentage',
            'discount_value' => 150,
        ]));

        $response->assertSessionHasErrors('discount_value');
        $this->assertDatabaseCount('promotions', 0);
    }

    public function test_delete_removes_promotion(): void
    {
        $this->actingAsAdmin()->post('/admin/promotions/create', $this->minimalPayload());
        $promotion = Promotion::query()->firstOrFail();

        $this->actingAsAdmin()->post("/admin/promotions/{$promotion->id}/delete")->assertRedirect('/admin/promotions');

        $this->assertDatabaseCount('promotions', 0);
    }
}
