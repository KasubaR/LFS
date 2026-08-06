<?php

namespace Tests\Feature\Console;

use App\Models\Membership;
use App\Models\MembershipPayment;
use App\Models\MembershipPlan;
use App\Models\User;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackfillReceiptNumbersCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SatelliteSeeder::class);
        $this->seed(MembershipPlanSeeder::class);
    }

    private function paidPaymentAt(string $paidAt, string $paymentReference): MembershipPayment
    {
        $user = User::factory()->create();
        $plan = MembershipPlan::query()->first();

        $membership = Membership::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'membership_number' => $paymentReference,
            'status' => 'active',
            'current_plan_id' => $plan->id,
            'approval_status' => 'approved',
        ]);

        return MembershipPayment::query()->create([
            'membership_id' => $membership->id,
            'plan_id' => $plan->id,
            'amount' => (float) $plan->price,
            'amount_paid' => (float) $plan->price,
            'currency' => 'ZMW',
            'payment_reference' => $paymentReference,
            'payment_gateway' => 'import',
            'status' => 'paid',
            'paid_at' => $paidAt,
        ]);
    }

    public function test_backfill_assigns_unique_receipt_numbers_in_chronological_order(): void
    {
        // Created out of chronological order on purpose — the command must
        // number them by paid_at, not by insertion/id order.
        $newest = $this->paidPaymentAt('2026-08-03 10:00:00', 'REF-NEWEST');
        $oldest = $this->paidPaymentAt('2026-08-01 10:00:00', 'REF-OLDEST');
        $middle = $this->paidPaymentAt('2026-08-02 10:00:00', 'REF-MIDDLE');

        $this->assertNull($oldest->receipt_number);
        $this->assertNull($middle->receipt_number);
        $this->assertNull($newest->receipt_number);

        $this->artisan('payments:backfill-receipt-numbers')->assertSuccessful();

        $oldest->refresh();
        $middle->refresh();
        $newest->refresh();

        $this->assertNotNull($oldest->receipt_number);
        $this->assertNotNull($middle->receipt_number);
        $this->assertNotNull($newest->receipt_number);

        $numbers = [$oldest->receipt_number, $middle->receipt_number, $newest->receipt_number];
        $this->assertSame($numbers, array_unique($numbers));

        // Chronological order (oldest paid_at first) must translate into
        // ascending sequence numbers.
        $this->assertTrue($oldest->receipt_number < $middle->receipt_number);
        $this->assertTrue($middle->receipt_number < $newest->receipt_number);
    }

    public function test_dry_run_does_not_write_receipt_numbers(): void
    {
        $payment = $this->paidPaymentAt('2026-08-01 10:00:00', 'REF-DRY-RUN');

        $this->artisan('payments:backfill-receipt-numbers', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull($payment->fresh()->receipt_number);
    }

    public function test_command_is_a_no_op_when_nothing_needs_backfilling(): void
    {
        $this->artisan('payments:backfill-receipt-numbers')->assertSuccessful();
    }
}
