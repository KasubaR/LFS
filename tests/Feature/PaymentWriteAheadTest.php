<?php

namespace Tests\Feature;

use App\Enums\MembershipStatus;
use App\Exceptions\LencoApiException;
use App\Models\Membership;
use App\Models\MembershipPayment;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\LencoService;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Our reference is saved BEFORE Lenco is called, a lost response never leaves
 * an unreconcilable charge, and a double-click cannot start two charges.
 */
class PaymentWriteAheadTest extends TestCase
{
    use RefreshDatabase;

    private const UNKNOWN = 'We could not confirm your payment request.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SatelliteSeeder::class);
        $this->seed(MembershipPlanSeeder::class);
    }

    /** @return array<string, mixed> */
    private function lencoOk(string $reference): array
    {
        return [
            'transactionId' => 'txn-1', 'lencoReference' => 'lenco-1', 'reference' => $reference,
            'status' => 'pay-offline', 'internalStatus' => 'pending', 'paymentInstructions' => 'Approve on your phone',
            'paymentUrl' => null, 'expiresAt' => null, 'currency' => 'ZMW', 'rawResponse' => [],
        ];
    }

    // ── Shop ─────────────────────────────────────────────────────────────────

    private function makeProduct(int $stock = 5): Product
    {
        return Product::query()->create([
            'id' => (string) Str::uuid(), 'name' => 'Test Jersey', 'slug' => 'test-jersey-'.Str::random(8),
            'price' => 100, 'category' => 'jerseys', 'gender' => 'unisex',
            'sizes' => [['size' => 'M', 'stock' => $stock]], 'total_stock' => $stock, 'is_active' => true,
        ]);
    }

    private function placeShopOrder(Product $product): \Illuminate\Testing\TestResponse
    {
        return $this->withSession([
            'cart' => [['price' => 100, 'qty' => 2, 'productId' => $product->id, 'size' => 'M']],
        ])->postJson('/shop/checkout/place-order', [
            'customerInfo' => ['name' => 'Jane Doe', 'email' => 'jane@example.com'],
            'paymentMethod' => 'mobile_money',
            'provider' => 'mtn',
            'customerPhone' => '+260971234567',
        ]);
    }

    public function test_shop_payment_row_exists_with_our_reference_before_lenco_is_called(): void
    {
        $product = $this->makeProduct();
        $seen = [];

        $this->mock(LencoService::class, function ($mock) use (&$seen) {
            $mock->shouldReceive('generateReference')->andReturn('LFS-WA-1');
            $mock->shouldReceive('initiateMobileMoneyPayment')->once()->andReturnUsing(function ($o, $p, $op, $ref) use (&$seen) {
                $row = Payment::query()->where('transaction_id', 'LFS-WA-1')->first();
                $seen = ['ref' => $ref, 'rowExists' => $row !== null, 'status' => $row?->status];

                return $this->lencoOk('LFS-WA-1');
            });
        });

        $this->placeShopOrder($product)->assertOk()->assertJson(['reference' => 'LFS-WA-1']);

        $this->assertSame(['ref' => 'LFS-WA-1', 'rowExists' => true, 'status' => 'pending'], $seen);
        $payment = Payment::query()->where('transaction_id', 'LFS-WA-1')->first();
        $this->assertSame('lenco-1', $payment->lenco_reference, 'gateway details are filled in afterwards');
        $this->assertSame('mtn', $payment->lenco_provider);
    }

    public function test_shop_lost_lenco_response_keeps_the_order_and_stock_for_reconciliation(): void
    {
        $product = $this->makeProduct();

        $this->mock(LencoService::class, function ($mock) {
            $mock->shouldReceive('generateReference')->andReturn('LFS-WA-2');
            $mock->shouldReceive('initiateMobileMoneyPayment')->once()
                ->andThrow(new LencoApiException('HTTP error: timeout', 0, true));
        });

        $response = $this->placeShopOrder($product);

        $response->assertStatus(502);
        $this->assertStringContainsString(self::UNKNOWN, $response->json('message'));
        $this->assertSame('pending_payment', Order::query()->first()->status);
        $this->assertSame('pending', Payment::query()->where('transaction_id', 'LFS-WA-2')->value('status'));
        $this->assertSame(3, $product->fresh()->total_stock, 'stock stays reserved until the charge is resolved');
    }

    public function test_shop_refusal_by_lenco_fails_the_order_and_restores_stock(): void
    {
        $product = $this->makeProduct();

        $this->mock(LencoService::class, function ($mock) {
            $mock->shouldReceive('generateReference')->andReturn('LFS-WA-3');
            $mock->shouldReceive('initiateMobileMoneyPayment')->once()
                ->andThrow(new LencoApiException('Invalid phone number', 422, false));
            $mock->shouldReceive('customerMessage')->andReturn('Please check your mobile money number.');
        });

        $this->placeShopOrder($product)->assertStatus(502)->assertJson(['message' => 'Please check your mobile money number.']);

        $this->assertSame('payment_failed', Order::query()->first()->status);
        $this->assertSame('failed', Payment::query()->where('transaction_id', 'LFS-WA-3')->value('status'));
        $this->assertSame(5, $product->fresh()->total_stock);
    }

    public function test_shop_immediate_decline_from_lenco_fails_the_order_and_restores_stock(): void
    {
        $product = $this->makeProduct();

        $this->mock(LencoService::class, function ($mock) {
            $mock->shouldReceive('generateReference')->andReturn('LFS-WA-4');
            $mock->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn(
                array_merge($this->lencoOk('LFS-WA-4'), ['status' => 'failed', 'internalStatus' => 'failed', 'failureReason' => 'declined'])
            );
            $mock->shouldReceive('customerMessage')->andReturn('Declined.');
        });

        $this->placeShopOrder($product)->assertStatus(502);

        $this->assertSame('payment_failed', Order::query()->first()->status);
        $this->assertSame(5, $product->fresh()->total_stock);
    }

    public function test_poller_cancels_a_shop_payment_lenco_never_received_and_restores_stock(): void
    {
        $product = $this->makeProduct();

        $this->mock(LencoService::class, function ($mock) {
            $mock->shouldReceive('generateReference')->andReturn('LFS-WA-5');
            $mock->shouldReceive('initiateMobileMoneyPayment')->once()
                ->andThrow(new LencoApiException('HTTP error: timeout', 0, true));
        });
        $this->placeShopOrder($product)->assertStatus(502);

        $payment = Payment::query()->where('transaction_id', 'LFS-WA-5')->first();

        // Too soon: Lenco may still be processing, so the poller leaves it alone.
        $payment->forceFill(['created_at' => now()->subMinutes(10)])->save();
        $this->mock(LencoService::class, function ($mock) {
            $mock->shouldReceive('verifyPayment')->andReturn(['status' => 'pending', 'internalStatus' => 'pending', 'found' => false]);
        });
        $this->artisan('payments:poll-pending')->assertSuccessful();
        $this->assertSame('pending', $payment->fresh()->status);

        // Long enough: Lenco has no record, so it was never sent.
        $payment->forceFill(['created_at' => now()->subMinutes(45)])->save();
        $this->artisan('payments:poll-pending')->assertSuccessful();

        $this->assertSame('cancelled', $payment->fresh()->status);
        $this->assertSame('cancelled', Order::query()->first()->status);
        $this->assertSame(5, $product->fresh()->total_stock);
    }

    // ── Membership ───────────────────────────────────────────────────────────

    private function draftMembership(User $user): Membership
    {
        return Membership::query()->create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'membership_number' => 'LFS-000901',
            'status' => MembershipStatus::Draft, 'current_plan_id' => MembershipPlan::query()->first()->id,
            'approval_status' => 'pending',
        ]);
    }

    /** @return array<string, string> */
    private function pay(): array
    {
        return ['provider' => 'mtn', 'phone' => '+260971234567'];
    }

    public function test_membership_reference_is_saved_before_lenco_is_called(): void
    {
        $user = User::factory()->create();
        $membership = $this->draftMembership($user);
        $seen = null;

        $this->mock(LencoService::class, function ($mock) use (&$seen, $membership) {
            $mock->shouldReceive('generateReference')->andReturn('LFS-MWA-1');
            $mock->shouldReceive('initiateMobileMoneyPayment')->once()->andReturnUsing(function ($o, $p, $op, $ref) use (&$seen, $membership) {
                $row = MembershipPayment::query()->where('membership_id', $membership->id)->first();
                $seen = [$ref, $row?->payment_reference, $row?->pending_charge_amount !== null];

                return $this->lencoOk('LFS-MWA-1');
            });
        });

        $this->actingAs($user)->postJson('/account/payment/initiate', $this->pay())->assertOk()->assertJson(['reference' => 'LFS-MWA-1']);

        $this->assertSame(['LFS-MWA-1', 'LFS-MWA-1', true], $seen);
        $this->assertSame('lenco-1', MembershipPayment::query()->first()->lenco_reference);
    }

    public function test_membership_lost_response_blocks_a_second_charge_until_resolved(): void
    {
        $user = User::factory()->create();
        $this->draftMembership($user);

        $this->mock(LencoService::class, function ($mock) {
            $mock->shouldReceive('generateReference')->andReturn('LFS-MWA-2');
            $mock->shouldReceive('initiateMobileMoneyPayment')->once()
                ->andThrow(new LencoApiException('HTTP error: timeout', 0, true));
        });

        $first = $this->actingAs($user)->postJson('/account/payment/initiate', $this->pay());
        $first->assertStatus(502);
        $this->assertStringContainsString(self::UNKNOWN, $first->json('message'));
        $this->assertSame('LFS-MWA-2', MembershipPayment::query()->first()->payment_reference);

        // A second click does not reach Lenco (initiate is ->once() above).
        $this->actingAs($user)->postJson('/account/payment/initiate', $this->pay())->assertStatus(409);
    }

    public function test_membership_refusal_frees_the_member_to_try_again(): void
    {
        $user = User::factory()->create();
        $this->draftMembership($user);

        $this->mock(LencoService::class, function ($mock) {
            $mock->shouldReceive('generateReference')->andReturn('LFS-MWA-3', 'LFS-MWA-3B');
            $mock->shouldReceive('initiateMobileMoneyPayment')->twice()->andReturnUsing(function () {
                static $n = 0;
                if (++$n === 1) {
                    throw new LencoApiException('Invalid phone number', 422, false);
                }

                return $this->lencoOk('LFS-MWA-3B');
            });
            $mock->shouldReceive('customerMessage')->andReturn('Check your number.');
        });

        $this->actingAs($user)->postJson('/account/payment/initiate', $this->pay())->assertStatus(502);

        $row = MembershipPayment::query()->first();
        $this->assertNull($row->payment_reference);
        $this->assertNull($row->pending_charge_amount);

        $this->actingAs($user)->postJson('/account/payment/initiate', $this->pay())
            ->assertOk()->assertJson(['reference' => 'LFS-MWA-3B']);
    }

    public function test_poller_releases_a_membership_reference_lenco_never_received(): void
    {
        $user = User::factory()->create();
        $this->draftMembership($user);

        $this->mock(LencoService::class, function ($mock) {
            $mock->shouldReceive('generateReference')->andReturn('LFS-MWA-4');
            $mock->shouldReceive('initiateMobileMoneyPayment')->once()
                ->andThrow(new LencoApiException('HTTP error: timeout', 0, true));
        });
        $this->actingAs($user)->postJson('/account/payment/initiate', $this->pay())->assertStatus(502);

        $payment = MembershipPayment::query()->first();
        $this->mock(LencoService::class, function ($mock) {
            $mock->shouldReceive('verifyPayment')->andReturn(['status' => 'pending', 'internalStatus' => 'pending', 'found' => false]);
        });

        // Recent: still held (Lenco may be mid-processing).
        $payment->forceFill(['created_at' => now()->subMinutes(10), 'updated_at' => now()->subMinutes(10)])->saveQuietly();
        $this->artisan('payments:poll-pending')->assertSuccessful();
        $this->assertSame('LFS-MWA-4', $payment->fresh()->payment_reference);

        // Old and unknown to Lenco: released.
        $payment->forceFill(['created_at' => now()->subMinutes(45), 'updated_at' => now()->subMinutes(45)])->saveQuietly();
        $this->artisan('payments:poll-pending')->assertSuccessful();

        $fresh = $payment->fresh();
        $this->assertNull($fresh->payment_reference);
        $this->assertNull($fresh->pending_charge_amount);
    }
}
