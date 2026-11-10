<?php

namespace Tests\Feature;

use App\Enums\BillingCycle;
use App\Enums\MembershipStatus;
use App\Exceptions\DuplicateMembershipNumberException;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Satellite;
use App\Models\User;
use App\Services\MemberImportService;
use App\Services\MembershipNumberRegistry;
use App\Services\MembershipService;
use App\Support\Uuid;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A membership number belongs to ONE person. A renewal is a new memberships row
 * carrying the same number forward (so the column itself can't be unique), and
 * the number registry is what stops two different people ever sharing one,
 * including look-alikes such as LFS14149 / LFS-14149 / "lfs 14149".
 */
class MembershipNumberUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private MembershipNumberRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SatelliteSeeder::class);
        $this->seed(MembershipPlanSeeder::class);
        $this->registry = app(MembershipNumberRegistry::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function plan(): MembershipPlan
    {
        return MembershipPlan::query()->where('billing_cycle', BillingCycle::Annual)->firstOrFail();
    }

    private function membershipFor(User $user, ?string $number, string $status = MembershipStatus::Active): Membership
    {
        return Membership::query()->create([
            'id' => Uuid::v4(),
            'user_id' => $user->id,
            'membership_number' => $number,
            'status' => $status,
            'current_plan_id' => $this->plan()->id,
            'approval_status' => 'approved',
        ]);
    }

    // ── The registry ─────────────────────────────────────────────────────────

    public function test_a_free_number_can_be_claimed_and_reclaiming_it_is_harmless(): void
    {
        $a = User::factory()->create();

        $this->registry->claim('LFS-000010', $a->id);
        $this->registry->claim('LFS-000010', $a->id);   // renewals / retries

        $this->assertSame($a->id, $this->registry->ownerOf('LFS-000010'));
        $this->assertSame(1, DB::table('membership_number_owners')->count());
    }

    public function test_a_different_person_cannot_claim_a_taken_number(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->registry->claim('LFS-000010', $a->id);

        try {
            $this->registry->claim('LFS-000010', $b->id);
            $this->fail('a second person must not get the same number');
        } catch (DuplicateMembershipNumberException $e) {
            $this->assertSame($a->id, $e->ownerUserId);
            $this->assertSame('DUPLICATE_MEMBERSHIP_NUMBER', $e->errorCode);
            $this->assertStringContainsString('LFS-000010', $e->getMessage());
        }

        $this->assertSame($a->id, $this->registry->ownerOf('LFS-000010'), 'the original owner keeps it');
    }

    public function test_look_alike_spellings_are_the_same_number(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->registry->claim('LFS14149', $a->id);

        foreach (['LFS-14149', 'lfs14149', 'lfs 14149', '  LFS-14149  ', 'Lfs.14149'] as $variant) {
            try {
                $this->registry->claim($variant, $b->id);
                $this->fail("'{$variant}' must clash with LFS14149");
            } catch (DuplicateMembershipNumberException) {
                $this->addToAssertionCount(1);
            }
            $this->assertSame($a->id, $this->registry->ownerOf($variant), "ownerOf('{$variant}')");
        }

        // Genuinely different numbers are fine.
        $this->registry->claim('LFS14150', $b->id);
        $this->registry->claim('LFS-014149', $b->id);   // leading zero: a different number
        $this->assertSame($b->id, $this->registry->ownerOf('LFS14150'));
    }

    public function test_blank_numbers_are_ignored(): void
    {
        $a = User::factory()->create();

        foreach (['', '   ', '---'] as $blank) {
            $this->registry->claim($blank, $a->id);
            $this->assertNull($this->registry->ownerOf($blank));
        }

        $this->assertSame(0, DB::table('membership_number_owners')->count());
    }

    public function test_the_database_itself_refuses_two_owners_for_one_number(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $row = fn (int $userId) => ['number_key' => 'LFS500', 'user_id' => $userId, 'created_at' => now(), 'updated_at' => now()];

        DB::table('membership_number_owners')->insert($row($a->id));

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('membership_number_owners')->insert($row($b->id));
    }

    public function test_deleting_a_user_frees_their_numbers(): void
    {
        $a = User::factory()->create();
        $this->registry->claim('LFS-000020', $a->id);

        $a->delete();

        $this->assertNull($this->registry->ownerOf('LFS-000020'));
    }

    // ── The model guard (any way a membership row gets a number) ─────────────

    public function test_a_membership_cannot_be_created_with_another_persons_number(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->membershipFor($a, 'LFS-000030');

        foreach (['LFS-000030', 'LFS000030', 'lfs 000030'] as $number) {
            try {
                $this->membershipFor($b, $number);
                $this->fail("'{$number}' must be refused for a different person");
            } catch (DuplicateMembershipNumberException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, Membership::query()->where('user_id', $b->id)->count(), 'no row is saved when refused');
        $this->assertSame($a->id, $this->registry->ownerOf('LFS-000030'));
    }

    public function test_the_same_person_may_hold_the_number_on_a_second_row_like_a_renewal(): void
    {
        $a = User::factory()->create();
        $this->membershipFor($a, 'LFS-000040', MembershipStatus::Expired);

        $renewal = $this->membershipFor($a, 'LFS-000040', MembershipStatus::PendingPayment);

        $this->assertSame('LFS-000040', $renewal->membership_number);
        $this->assertSame(2, Membership::query()->where('user_id', $a->id)->count());
    }

    public function test_memberships_without_a_number_yet_are_fine(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();

        $this->membershipFor($a, null, MembershipStatus::Draft);
        $this->membershipFor($b, null, MembershipStatus::Draft);

        $this->assertSame(2, Membership::query()->whereNull('membership_number')->count());
    }

    public function test_changing_a_number_to_one_someone_else_holds_is_refused_but_a_free_one_is_claimed(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->membershipFor($a, 'LFS-000050');
        $mine = $this->membershipFor($b, 'LFS-000051');

        try {
            $mine->update(['membership_number' => 'LFS-000050']);
            $this->fail('taking another persons number must be refused');
        } catch (DuplicateMembershipNumberException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('LFS-000051', $mine->fresh()->membership_number);

        $mine->update(['membership_number' => 'LFS-000052']);
        $this->assertSame($b->id, $this->registry->ownerOf('LFS-000052'));
    }

    public function test_updating_other_fields_does_not_touch_the_registry(): void
    {
        $a = User::factory()->create();
        $m = $this->membershipFor($a, 'LFS-000060');
        DB::table('membership_number_owners')->truncate();

        $m->update(['status' => MembershipStatus::Suspended]);

        $this->assertSame(0, DB::table('membership_number_owners')->count(), 'only number changes are checked');
    }

    // ── New numbers (first payment) ──────────────────────────────────────────

    public function test_generated_numbers_are_sequential_and_reserved_for_the_person(): void
    {
        $service = app(MembershipService::class);
        $a = User::factory()->create();
        $b = User::factory()->create();

        $first = $service->generateMembershipNumber($a->id);
        $second = $service->generateMembershipNumber($b->id);

        $this->assertSame('LFS-000001', $first);
        $this->assertSame('LFS-000002', $second);
        $this->assertSame($a->id, $this->registry->ownerOf($first));
        $this->assertSame($b->id, $this->registry->ownerOf($second));
    }

    public function test_a_generated_number_that_an_import_already_holds_is_skipped(): void
    {
        // A legacy import 'LFS014149' looks exactly like new member 14149 (LFS-014149).
        $legacy = User::factory()->create();
        $this->membershipFor($legacy, 'LFS014149');
        DB::table('membership_number_counters')->updateOrInsert(['prefix' => 'LFS'], ['next_value' => 14149]);

        $newcomer = User::factory()->create();
        $number = app(MembershipService::class)->generateMembershipNumber($newcomer->id);

        $this->assertSame('LFS-014150', $number, 'LFS-014149 is held by the legacy member, so it is skipped');
        $this->assertSame($legacy->id, $this->registry->ownerOf('LFS-014149'));
        $this->assertSame($newcomer->id, $this->registry->ownerOf('LFS-014150'));
    }

    public function test_first_payment_gives_each_new_member_their_own_number(): void
    {
        Carbon::setTestNow('2026-02-10 10:00:00');
        $service = app(MembershipService::class);
        $numbers = [];

        foreach (range(1, 3) as $i) {
            $user = User::factory()->create(['satellite_id' => Satellite::query()->first()->id]);
            $created = $service->createApplication($user->id, $this->plan()->id);
            $submitted = $service->submitApplication($created['membershipId']);
            $active = $service->handlePaymentUpdate($submitted['latestPayment']['id'], 1000.00);

            $numbers[] = $active['membershipNumber'];
            $this->assertSame($user->id, $this->registry->ownerOf($active['membershipNumber']));
        }

        $this->assertCount(3, array_unique($numbers), 'three people, three different numbers');
    }

    public function test_a_renewal_keeps_the_same_number_and_the_same_owner(): void
    {
        Carbon::setTestNow('2026-02-10 10:00:00');
        $service = app(MembershipService::class);
        $user = User::factory()->create(['satellite_id' => Satellite::query()->first()->id]);

        $created = $service->createApplication($user->id, $this->plan()->id);
        $submitted = $service->submitApplication($created['membershipId']);
        $number = $service->handlePaymentUpdate($submitted['latestPayment']['id'], 1000.00)['membershipNumber'];
        $service->expire($created['membershipId']);

        $renewal = $service->startRenewal($user->id, $this->plan()->id);

        $this->assertSame($number, $renewal['membershipNumber']);
        $this->assertSame($user->id, $this->registry->ownerOf($number));
        $this->assertSame(1, DB::table('membership_number_owners')->count());
    }

    public function test_a_renewal_is_blocked_if_the_number_has_somehow_been_given_to_someone_else(): void
    {
        // Data that predates the registry could already hold a shared number. The
        // earlier holder keeps it; the other person's renewal fails loudly instead
        // of silently continuing to share it.
        Carbon::setTestNow('2026-02-10 10:00:00');
        $holder = User::factory()->create();
        $other = User::factory()->create(['satellite_id' => Satellite::query()->first()->id]);
        $this->registry->claim('LFS-000070', $holder->id);
        DB::table('memberships')->insert([
            'id' => Uuid::v4(), 'user_id' => $other->id, 'membership_number' => 'LFS-000070',
            'status' => MembershipStatus::Expired, 'current_plan_id' => $this->plan()->id,
            'approval_status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(DuplicateMembershipNumberException::class);
        app(MembershipService::class)->startRenewal($other->id, $this->plan()->id);
    }

    // ── Imports ──────────────────────────────────────────────────────────────

    public function test_import_skips_a_row_whose_number_is_a_look_alike_of_an_existing_member(): void
    {
        // Existing member stored with a dash; the spreadsheet ref 13239 becomes
        // LFS13239. The exact-match check would miss this, but logins treat them
        // as the same number.
        $existing = User::factory()->create();
        $this->membershipFor($existing, 'LFS-13239');

        $result = app(MemberImportService::class)->importFromFile(base_path('tests/fixtures/member-import-sample.csv'), 'test:import');

        $this->assertTrue(
            collect($result['errors'])->contains(fn ($m) => str_contains($m, 'alice.import@test.com') && str_contains($m, 'already belongs to another member')),
            'Alice (ref 13239) is skipped'
        );
        $this->assertNull(User::query()->where('email', 'alice.import@test.com')->first(), 'no account is created for a skipped row');
        $this->assertSame($existing->id, $this->registry->ownerOf('LFS13239'));
    }

    public function test_a_clean_import_registers_every_imported_number(): void
    {
        $result = app(MemberImportService::class)->importFromFile(base_path('tests/fixtures/member-import-sample.csv'), 'test:import');

        $this->assertGreaterThan(0, $result['importedRows']);
        $alice = User::query()->where('email', 'alice.import@test.com')->firstOrFail();
        $this->assertSame($alice->id, $this->registry->ownerOf('LFS13239'));
        $this->assertSame($alice->id, $this->registry->ownerOf('LFS-13239'), 'and the dashed spelling resolves to the same owner');
    }

    // ── The clash report ─────────────────────────────────────────────────────

    public function test_clash_report_is_clean_when_every_number_has_one_owner(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->membershipFor($a, 'LFS-000080', MembershipStatus::Expired);
        $this->membershipFor($a, 'LFS-000080', MembershipStatus::Active);   // renewal: same person
        $this->membershipFor($b, 'LFS-000081');

        $this->artisan('membership:check-number-clashes')
            ->expectsOutputToContain('No membership number is shared by different people.')
            ->assertSuccessful();
    }

    public function test_clash_report_lists_numbers_shared_by_different_people_and_fails(): void
    {
        $a = User::factory()->create(['email' => 'first.holder@example.test']);
        $b = User::factory()->create(['email' => 'second.holder@example.test']);
        $row = fn (User $u, string $n) => [
            'id' => Uuid::v4(), 'user_id' => $u->id, 'membership_number' => $n, 'status' => MembershipStatus::Active,
            'current_plan_id' => $this->plan()->id, 'approval_status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
        ];
        // Written straight to the table, as old data or a script could have been.
        DB::table('memberships')->insert([$row($a, 'LFS14149'), $row($b, 'LFS-14149')]);

        $this->artisan('membership:check-number-clashes')
            ->expectsOutputToContain('1 membership number(s) are held by more than one person')
            ->expectsOutputToContain('first.holder@example.test')
            ->expectsOutputToContain('second.holder@example.test')
            ->assertFailed();

        // Read-only: it changed nothing.
        $this->assertSame(2, DB::table('memberships')->count());
    }

    // ── The migration backfill ───────────────────────────────────────────────

    public function test_the_migration_backfills_existing_numbers_and_survives_old_clashes(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $third = User::factory()->create();
        $row = fn (User $u, string $n, string $created) => [
            'id' => Uuid::v4(), 'user_id' => $u->id, 'membership_number' => $n, 'status' => MembershipStatus::Active,
            'current_plan_id' => $this->plan()->id, 'approval_status' => 'approved', 'created_at' => $created, 'updated_at' => $created,
        ];
        DB::table('memberships')->insert([
            $row($first, 'LFS14149', '2026-01-01 00:00:00'),
            $row($second, 'LFS-14149', '2026-02-01 00:00:00'),   // clash: later, loses
            $row($third, 'LFS-000090', '2026-03-01 00:00:00'),
        ]);

        $migration = require database_path('migrations/2026_11_10_000001_create_membership_number_owners_table.php');
        $migration->down();
        $migration->up();

        $this->assertSame($first->id, $this->registry->ownerOf('LFS14149'), 'the earliest holder keeps the number');
        $this->assertSame($third->id, $this->registry->ownerOf('LFS-000090'));
        $this->assertSame(2, DB::table('membership_number_owners')->count());
    }
}
