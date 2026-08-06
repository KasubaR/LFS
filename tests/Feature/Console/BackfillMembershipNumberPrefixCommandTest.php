<?php

namespace Tests\Feature\Console;

use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\User;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackfillMembershipNumberPrefixCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SatelliteSeeder::class);
        $this->seed(MembershipPlanSeeder::class);
    }

    private function membershipWithNumber(string $number): Membership
    {
        $user = User::factory()->create();
        $plan = MembershipPlan::query()->first();

        return Membership::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'membership_number' => $number,
            'status' => 'active',
            'current_plan_id' => $plan->id,
            'approval_status' => 'approved',
        ]);
    }

    public function test_prefixes_bare_numeric_membership_numbers(): void
    {
        $legacyA = $this->membershipWithNumber('14149');
        $legacyB = $this->membershipWithNumber('13239');
        $alreadyPrefixed = $this->membershipWithNumber('LFS-000042');

        $this->artisan('member-import:backfill-lfs-prefix')->assertSuccessful();

        $this->assertSame('LFS14149', $legacyA->fresh()->membership_number);
        $this->assertSame('LFS13239', $legacyB->fresh()->membership_number);
        // Already-prefixed numbers are left exactly as they were.
        $this->assertSame('LFS-000042', $alreadyPrefixed->fresh()->membership_number);
    }

    public function test_non_numeric_stray_rows_are_left_untouched_and_reported(): void
    {
        $stray = $this->membershipWithNumber('DIAG-GATE-1');

        $this->artisan('member-import:backfill-lfs-prefix')
            ->assertSuccessful()
            ->expectsOutputToContain('DIAG-GATE-1');

        $this->assertSame('DIAG-GATE-1', $stray->fresh()->membership_number);
    }

    public function test_dry_run_does_not_write_anything(): void
    {
        $legacy = $this->membershipWithNumber('14149');

        $this->artisan('member-import:backfill-lfs-prefix', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame('14149', $legacy->fresh()->membership_number);
    }

    public function test_command_is_a_no_op_when_nothing_needs_backfilling(): void
    {
        $this->membershipWithNumber('LFS-000001');

        $this->artisan('member-import:backfill-lfs-prefix')->assertSuccessful();
    }
}
