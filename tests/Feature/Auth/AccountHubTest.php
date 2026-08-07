<?php

namespace Tests\Feature\Auth;

use App\Enums\MembershipStatus;
use App\Enums\TShirtSize;
use App\Models\Membership;
use App\Models\MembershipPayment;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Satellite;
use App\Models\User;
use App\Models\WishlistItem;
use App\Services\LencoService;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountHubTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SatelliteSeeder::class);
        $this->seed(MembershipPlanSeeder::class);
    }

    private function member(): User
    {
        $user = User::factory()->create([
            'phone' => '+260971111111',
            'gender' => 'male',
            'nationality' => 'Zambian',
            'town' => 'Lusaka',
            't_shirt_size' => TShirtSize::M,
            'satellite_id' => Satellite::query()->first()->id,
        ]);

        $plan = MembershipPlan::query()->first();

        Membership::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'membership_number' => 'LFS-HUB-001',
            'status' => MembershipStatus::Active,
            'current_plan_id' => $plan->id,
            'approval_status' => 'approved',
            'start_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addMonths(5)->toDateString(),
        ]);

        return $user;
    }

    private function createProduct(string $name = 'Race Tee'): Product
    {
        return Product::query()->create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(5),
            'price' => 250,
            'category' => 'apparel',
            'gender' => 'unisex',
            'sizes' => [['size' => 'M', 'stock' => 5]],
            'total_stock' => 5,
            'is_active' => true,
            'featured' => false,
            'sort_order' => 1,
        ]);
    }

    public function test_account_tabs_render_with_active_markers(): void
    {
        $user = $this->member();

        $dashboard = $this->actingAs($user)->get('/account');
        $dashboard->assertOk();
        $dashboard->assertSee('account-tabs', false);
        $dashboard->assertSee('aria-current="page"', false);
        $dashboard->assertSee('Membership', false);
        $dashboard->assertSee('active', false);
        $dashboard->assertSee('Edit Profile', false);
        $dashboard->assertSee('Sign Out', false);
        $dashboard->assertSee('LFS-HUB-001', false);
        $dashboard->assertSee('Membership number', false);
        $dashboard->assertSee('Expiry', false);
        $dashboard->assertSee('Satellite', false);
        $dashboard->assertSee($user->satellite->name, false);
        $dashboard->assertSee('page-no-nav', false);
        $dashboard->assertSee('page-breadcrumb', false);
        $dashboard->assertDontSee('class="lfs-nav"', false);
        $dashboard->assertSee('Contact LFS', false);
        $dashboard->assertSee('info@lfszambia.run', false);
        $dashboard->assertSee('+260 767 023 948', false);
        $dashboard->assertSee('Dashboard', false);
        $dashboard->assertSee('Orders', false);
        $dashboard->assertSee('Wishlist', false);
        $dashboard->assertSee('Settings', false);

        $orders = $this->actingAs($user)->get('/account/orders');
        $orders->assertOk();
        $orders->assertSee('No orders yet', false);

        $wishlist = $this->actingAs($user)->get('/account/wishlist');
        $wishlist->assertOk();
        $wishlist->assertSee('Your wishlist is empty', false);

        $settings = $this->actingAs($user)->get('/account/settings');
        $settings->assertOk();
        $settings->assertSee('Personal Details', false);
        $settings->assertSee('Update your name, email, and phone', false);
        $settings->assertSee('Change Password', false);
        $settings->assertSee('Update your account password', false);

        $personal = $this->actingAs($user)->get('/account/settings/personal');
        $personal->assertOk();
        $personal->assertSee('Save changes', false);
        $personal->assertSee($user->email, false);
    }

    public function test_unpaid_member_sees_pending_number_and_no_announcements_or_events(): void
    {
        $user = User::factory()->create([
            'phone' => '+260971111112',
            'gender' => 'male',
            'nationality' => 'Zambian',
            'town' => 'Lusaka',
            't_shirt_size' => TShirtSize::M,
            'satellite_id' => Satellite::query()->first()->id,
        ]);

        $plan = MembershipPlan::query()->first();

        Membership::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'membership_number' => null,
            'status' => MembershipStatus::PendingPayment,
            'current_plan_id' => $plan->id,
            'approval_status' => 'pending',
        ]);

        $dashboard = $this->actingAs($user)->get('/account');
        $dashboard->assertOk();
        $dashboard->assertSee('Pending', false);
        $dashboard->assertSee('Announcements unlock once your membership payment is confirmed.', false);
        $dashboard->assertSee('Browse the LFS events calendar', false);
    }

    public function test_guest_is_redirected_from_account_tabs(): void
    {
        $this->get('/account')->assertRedirect('/login');
        $this->get('/account/orders')->assertRedirect('/login');
        $this->get('/account/wishlist')->assertRedirect('/login');
        $this->get('/account/settings')->assertRedirect('/login');
    }

    public function test_orders_include_owned_and_legacy_email_matches(): void
    {
        $user = $this->member();
        $other = User::factory()->create(['email' => 'other@example.com']);

        $owned = Order::query()->create([
            'user_id' => $user->id,
            'order_number' => 'LFS-OWNED-1',
            'customer_name' => $user->name,
            'customer_email' => $user->email,
            'subtotal' => 100,
            'total' => 100,
            'status' => 'paid',
        ]);

        OrderItem::query()->create([
            'order_id' => $owned->id,
            'product_id' => 'prod-1',
            'name' => 'Cap',
            'size' => 'M',
            'qty' => 1,
            'unit_price' => 100,
            'line_total' => 100,
        ]);

        Order::query()->create([
            'user_id' => null,
            'order_number' => 'LFS-LEGACY-1',
            'customer_name' => $user->name,
            'customer_email' => $user->email,
            'subtotal' => 50,
            'total' => 50,
            'status' => 'paid',
        ]);

        Order::query()->create([
            'user_id' => $other->id,
            'order_number' => 'LFS-OTHER-1',
            'customer_name' => 'Other',
            'customer_email' => $other->email,
            'subtotal' => 75,
            'total' => 75,
            'status' => 'paid',
        ]);

        $response = $this->actingAs($user)->get('/account/orders');
        $response->assertOk();
        $response->assertSee('LFS-OWNED-1', false);
        $response->assertSee('LFS-LEGACY-1', false);
        $response->assertDontSee('LFS-OTHER-1', false);

        $detail = $this->actingAs($user)->get('/account/orders/LFS-OWNED-1');
        $detail->assertOk();
        $detail->assertSee('Cap', false);

        $this->actingAs($user)->get('/account/orders/LFS-OTHER-1')->assertNotFound();
    }

    public function test_authenticated_checkout_stores_user_id_on_order(): void
    {
        $user = $this->member();
        $product = $this->createProduct('Tee');

        $this->mock(LencoService::class, function ($mock) {
            $mock->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
                'transactionId' => 'txn-hub-1',
                'lencoReference' => 'lenco-hub-1',
                'reference' => 'LFS-HUB-REF-1',
                'status' => 'pay-offline',
                'internalStatus' => 'pending',
                'paymentInstructions' => 'Approve on your phone',
                'paymentUrl' => null,
                'expiresAt' => null,
                'currency' => 'ZMW',
                'rawResponse' => [],
            ]);
        });

        $response = $this->actingAs($user)->withSession([
            'cart' => [['price' => 100, 'qty' => 1, 'productId' => $product->id, 'name' => 'Tee', 'size' => 'M']],
        ])->postJson('/shop/checkout/place-order', [
            'customerInfo' => ['name' => $user->name, 'email' => $user->email, 'phone' => $user->phone],
            'paymentMethod' => 'mobile_money',
            'provider' => 'mtn',
            'customerPhone' => '+260971234567',
        ]);

        $response->assertOk();
        $orderNumber = $response->json('orderNumber');
        $order = Order::query()->where('order_number', $orderNumber)->first();

        $this->assertNotNull($order);
        $this->assertSame((int) $user->id, (int) $order->user_id);
    }

    public function test_wishlist_add_remove_and_idempotent_add(): void
    {
        $user = $this->member();
        $product = $this->createProduct('Squad Singlet');

        $this->actingAs($user)
            ->post('/account/wishlist', ['product_id' => $product->id])
            ->assertRedirect();

        $this->assertDatabaseHas('wishlist_items', [
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);

        $this->actingAs($user)
            ->post('/account/wishlist', ['product_id' => $product->id])
            ->assertRedirect();

        $this->assertSame(1, WishlistItem::query()->where('user_id', $user->id)->count());

        $page = $this->actingAs($user)->get('/account/wishlist');
        $page->assertOk();
        $page->assertSee('Squad Singlet', false);

        $this->actingAs($user)
            ->delete('/account/wishlist/'.$product->id)
            ->assertRedirect();

        $this->assertDatabaseMissing('wishlist_items', [
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_settings_update_validates_and_saves_profile(): void
    {
        $user = $this->member();
        $satellite = Satellite::query()->where('slug', 'avondale')->first();

        $this->actingAs($user)
            ->post('/account/settings/personal', [
                'last_name' => 'Runner',
                'other_names' => 'Updated',
                'phone' => '+260977777777',
                'gender' => 'female',
                'nationality' => 'Zambian',
                'satellite_id' => $satellite->id,
                'town' => 'Ndola',
                't_shirt_size' => TShirtSize::L,
            ])
            ->assertRedirect(route('account.settings.personal'));

        $user->refresh();
        $this->assertSame('Updated Runner', $user->name);
        $this->assertSame('+260977777777', $user->phone);
        $this->assertSame('female', $user->gender);
        $this->assertSame((int) $satellite->id, (int) $user->satellite_id);
        $this->assertSame('Ndola', $user->town);
        $this->assertSame(TShirtSize::L, $user->t_shirt_size);

        $this->actingAs($user)
            ->from('/account/settings/personal')
            ->post('/account/settings/personal', [
                'last_name' => '',
                'other_names' => '',
                'phone' => 'abc',
                'gender' => 'nope',
                'nationality' => '',
                'satellite_id' => 999999,
                'town' => '',
                't_shirt_size' => 'HUGE',
            ])
            ->assertRedirect('/account/settings/personal')
            ->assertSessionHasErrors(['last_name', 'other_names', 'phone', 'gender', 'nationality', 'satellite_id', 'town', 't_shirt_size']);
    }

    public function test_settings_subpages_render(): void
    {
        $user = $this->member();

        $this->actingAs($user)->get('/account/settings/password')
            ->assertOk()
            ->assertSee('Update your account password', false)
            ->assertSee('Current password', false);

        $this->actingAs($user)->get('/account/settings/personal')
            ->assertOk()
            ->assertSee('Profile photo', false)
            ->assertSee('account-avatar-field', false);
    }

    public function test_avatar_upload_replace_and_remove(): void
    {
        $user = $this->member();
        $satellite = Satellite::query()->where('slug', 'avondale')->first();
        $base = [
            'last_name' => $user->last_name,
            'other_names' => $user->other_names,
            'phone' => $user->phone,
            'gender' => $user->gender,
            'nationality' => $user->nationality,
            'satellite_id' => $satellite->id,
            'town' => $user->town,
            't_shirt_size' => $user->t_shirt_size,
        ];

        $upload = UploadedFile::fake()->image('runner.jpg', 400, 400);

        $this->actingAs($user)
            ->post('/account/settings/personal', array_merge($base, [
                'avatar' => $upload,
            ]))
            ->assertRedirect(route('account.settings.personal'));

        $user->refresh();
        $this->assertNotNull($user->avatar_path);
        $this->assertFileExists(public_path(ltrim($user->avatar_path, '/')));
        $firstPath = $user->avatar_path;

        $dashboard = $this->actingAs($user)->get('/account');
        $dashboard->assertOk();
        $dashboard->assertSee('account-profile-card__avatar', false);
        $dashboard->assertSee($user->avatar_path, false);

        $replacement = UploadedFile::fake()->image('runner-new.png', 320, 320);
        $this->actingAs($user)
            ->post('/account/settings/personal', array_merge($base, [
                'avatar' => $replacement,
            ]))
            ->assertRedirect(route('account.settings.personal'));

        $user->refresh();
        $this->assertNotSame($firstPath, $user->avatar_path);
        $this->assertFileDoesNotExist(public_path(ltrim($firstPath, '/')));
        $this->assertFileExists(public_path(ltrim($user->avatar_path, '/')));
        $secondPath = $user->avatar_path;

        $this->actingAs($user)
            ->post('/account/settings/personal', array_merge($base, [
                'remove_avatar' => '1',
            ]))
            ->assertRedirect(route('account.settings.personal'));

        $user->refresh();
        $this->assertNull($user->avatar_path);
        $this->assertFileDoesNotExist(public_path(ltrim($secondPath, '/')));
    }

    public function test_avatar_upload_rejects_invalid_files(): void
    {
        $user = $this->member();
        $satellite = Satellite::query()->where('slug', 'avondale')->first();

        $this->actingAs($user)
            ->from('/account/settings/personal')
            ->post('/account/settings/personal', [
                'last_name' => $user->last_name,
                'other_names' => $user->other_names,
                'phone' => $user->phone,
                'gender' => $user->gender,
                'nationality' => $user->nationality,
                'satellite_id' => $satellite->id,
                'town' => $user->town,
                't_shirt_size' => $user->t_shirt_size,
                'avatar' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
            ])
            ->assertRedirect('/account/settings/personal')
            ->assertSessionHasErrors(['avatar']);

        $this->actingAs($user)
            ->from('/account/settings/personal')
            ->post('/account/settings/personal', [
                'last_name' => $user->last_name,
                'other_names' => $user->other_names,
                'phone' => $user->phone,
                'gender' => $user->gender,
                'nationality' => $user->nationality,
                'satellite_id' => $satellite->id,
                'town' => $user->town,
                't_shirt_size' => $user->t_shirt_size,
                'avatar' => UploadedFile::fake()->image('huge.jpg')->size(3072),
            ])
            ->assertRedirect('/account/settings/personal')
            ->assertSessionHasErrors(['avatar']);
    }

    public function test_payment_history_lists_payments_with_downloadable_receipts(): void
    {
        $user = $this->member();
        $membership = Membership::query()->where('user_id', $user->id)->first();

        $paid = MembershipPayment::query()->create([
            'membership_id' => $membership->id,
            'plan_id' => $membership->current_plan_id,
            'amount' => 1000,
            'amount_paid' => 1000,
            'currency' => 'ZMW',
            'payment_reference' => 'LFS-PAY-PAID-1',
            'receipt_number' => 'LFS-RCT-000042',
            'payment_gateway' => 'lenco',
            'status' => 'paid',
            'paid_at' => now(),
            'covers_from' => now()->subMonth(),
            'covers_to' => now()->addMonths(11),
        ]);

        MembershipPayment::query()->create([
            'membership_id' => $membership->id,
            'plan_id' => $membership->current_plan_id,
            'amount' => 1000,
            'amount_paid' => 0,
            'currency' => 'ZMW',
            'payment_reference' => 'LFS-PAY-PENDING-1',
            'payment_gateway' => 'lenco',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)->get('/account/payments');
        $response->assertOk();
        // The paid row has a receipt_number, so it takes priority over the
        // raw gateway payment_reference in the list's headline.
        $response->assertSee('LFS-RCT-000042', false);
        $response->assertDontSee('LFS-PAY-PAID-1', false);
        // The pending row has no receipt_number yet, so it still falls back
        // to showing its payment_reference.
        $response->assertSee('LFS-PAY-PENDING-1', false);
        $response->assertSee('Download receipt', false);

        $receipt = $this->actingAs($user)->get("/account/payments/{$paid->id}/receipt");
        $receipt->assertOk();
        $receipt->assertHeader('content-type', 'application/pdf');
    }

    public function test_suspended_membership_outranks_an_older_expired_one_on_the_dashboard(): void
    {
        // Regression test: resolveDisplayMembership()'s priority ranking
        // used to omit Suspended entirely, so it fell back to the default
        // (lowest) rank — worse than even Expired/Cancelled. A member with
        // both an old Expired membership and a currently-Suspended one (this
        // year's, non-payment) would then see the stale Expired record
        // instead of the actionable Suspended one.
        $user = $this->member();
        $plan = MembershipPlan::query()->first();

        $expired = Membership::query()->where('user_id', $user->id)->first();
        $expired->update([
            'status' => MembershipStatus::Expired,
            'expiry_date' => now()->subMonths(8)->toDateString(),
        ]);

        $suspended = Membership::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'membership_number' => 'LFS-HUB-002',
            'status' => MembershipStatus::Suspended,
            'current_plan_id' => $plan->id,
            'approval_status' => 'approved',
            'start_date' => now()->subMonths(4)->toDateString(),
            'expiry_date' => now()->addMonths(8)->toDateString(),
        ]);

        $dashboard = $this->actingAs($user)->get('/account');
        $dashboard->assertOk();
        $dashboard->assertSee($suspended->membership_number, false);
        $dashboard->assertDontSee($expired->membership_number, false);
    }

    public function test_active_member_in_grace_period_can_reach_balance_page_from_dashboard_reminder(): void
    {
        $user = $this->member();
        $membership = Membership::query()->where('user_id', $user->id)->first();
        $membership->update(['grace_period_ends_at' => now()->addMonths(2)]);

        MembershipPayment::query()->create([
            'membership_id' => $membership->id,
            'plan_id' => $membership->current_plan_id,
            'amount' => 1000,
            'amount_paid' => 250,
            'currency' => 'ZMW',
            'payment_reference' => 'LFS-PAY-GRACE-1',
            'payment_gateway' => 'lenco',
            'status' => 'partially_paid',
        ]);

        // The dashboard reminder banner links here — it must not bounce the
        // member back to /account (that only happens for Suspended members,
        // see AccountController::balance()).
        $dashboard = $this->actingAs($user)->get('/account');
        $dashboard->assertOk();
        $dashboard->assertSee(route('account.balance'), false);

        $balance = $this->actingAs($user)->get('/account/balance');
        $balance->assertOk();
        $balance->assertSee('Outstanding balance', false);
        $balance->assertSee('K750.00', false);
        $balance->assertSee('account-payment-form', false);
    }

    public function test_payment_receipt_is_owner_scoped_and_requires_a_completed_payment(): void
    {
        $owner = $this->member();
        $membership = Membership::query()->where('user_id', $owner->id)->first();

        $paidPayment = MembershipPayment::query()->create([
            'membership_id' => $membership->id,
            'plan_id' => $membership->current_plan_id,
            'amount' => 1000,
            'amount_paid' => 1000,
            'payment_reference' => 'LFS-PAY-OWNED-1',
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $unpaidPayment = MembershipPayment::query()->create([
            'membership_id' => $membership->id,
            'plan_id' => $membership->current_plan_id,
            'amount' => 1000,
            'amount_paid' => 0,
            'payment_reference' => 'LFS-PAY-UNPAID-1',
            'status' => 'pending',
        ]);

        $intruder = $this->member();

        $this->actingAs($intruder)
            ->get("/account/payments/{$paidPayment->id}/receipt")
            ->assertNotFound();

        $this->actingAs($owner)
            ->get("/account/payments/{$unpaidPayment->id}/receipt")
            ->assertNotFound();
    }
}
