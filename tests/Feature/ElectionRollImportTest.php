<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Enums\ElectionStatus;
use App\Enums\ElectionType;
use App\Enums\ElectionVoterMatchStatus;
use App\Models\AdminUser;
use App\Models\Election;
use App\Models\ElectionVoter;
use App\Models\User;
use App\Services\Election\ElectionRollImportService;
use App\Support\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Regression coverage for the roll matching rule in PDF §3: a member can be
 * identified by membership number, email, OR phone — each independently
 * sufficient, not a fallback chain gated on membership number being present.
 */
class ElectionRollImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_row_matches_by_email_alone_with_no_membership_number(): void
    {
        $admin = $this->makeEcAdmin();
        $election = $this->makeElection();
        $user = User::factory()->create(['email' => 'email-only@example.com', 'phone' => null]);

        $csv = $this->writeCsv([
            ['membership_number', 'email', 'phone', 'name'],
            ['', 'email-only@example.com', '', 'Email Only'],
        ]);

        app(ElectionRollImportService::class)->import($election, $csv, $admin);

        $voter = ElectionVoter::query()->where('election_id', $election->id)->first();
        $this->assertSame(ElectionVoterMatchStatus::Matched, $voter->match_status);
        $this->assertSame($user->id, $voter->user_id);
    }

    public function test_row_matches_by_phone_alone_with_no_membership_number_or_email(): void
    {
        $admin = $this->makeEcAdmin();
        $election = $this->makeElection();
        $user = User::factory()->create(['phone' => '+260977123456']);

        $csv = $this->writeCsv([
            ['membership_number', 'email', 'phone', 'name'],
            ['', '', '0977123456', 'Phone Only'],
        ]);

        app(ElectionRollImportService::class)->import($election, $csv, $admin);

        $voter = ElectionVoter::query()->where('election_id', $election->id)->first();
        $this->assertSame(ElectionVoterMatchStatus::Matched, $voter->match_status);
        $this->assertSame($user->id, $voter->user_id);
    }

    public function test_row_with_none_of_the_three_identifiers_matching_is_unmatched(): void
    {
        $admin = $this->makeEcAdmin();
        $election = $this->makeElection();

        $csv = $this->writeCsv([
            ['membership_number', 'email', 'phone', 'name'],
            ['', 'nobody@example.com', '', 'Nobody'],
        ]);

        app(ElectionRollImportService::class)->import($election, $csv, $admin);

        $voter = ElectionVoter::query()->where('election_id', $election->id)->first();
        $this->assertSame(ElectionVoterMatchStatus::Unmatched, $voter->match_status);
        $this->assertNull($voter->user_id);
    }

    public function test_row_matching_two_different_members_across_identifiers_is_ambiguous(): void
    {
        $admin = $this->makeEcAdmin();
        $election = $this->makeElection();
        $byEmail = User::factory()->create(['email' => 'ambiguous@example.com']);
        $byPhone = User::factory()->create(['phone' => '+260971112222']);

        $csv = $this->writeCsv([
            ['membership_number', 'email', 'phone', 'name'],
            ['', 'ambiguous@example.com', '0971112222', 'Two People'],
        ]);

        app(ElectionRollImportService::class)->import($election, $csv, $admin);

        $voter = ElectionVoter::query()->where('election_id', $election->id)->first();
        $this->assertSame(ElectionVoterMatchStatus::Ambiguous, $voter->match_status);
        $this->assertNull($voter->user_id);
        $this->assertNotSame($byEmail->id, $byPhone->id);
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function writeCsv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'roll-test-').'.csv';
        $handle = fopen($path, 'w');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        return $path;
    }

    private function makeEcAdmin(): AdminUser
    {
        return AdminUser::query()->create([
            'name' => 'EC', 'email' => 'roll-ec@example.com', 'password' => Hash::make('password'),
            'role' => AdminRole::ElectoralCommission, 'is_active' => true,
        ]);
    }

    private function makeElection(): Election
    {
        return Election::query()->create([
            'id' => Uuid::v4(), 'title' => 'Roll Import Test', 'type' => ElectionType::Egm,
            'status' => ElectionStatus::Draft, 'scheduled_open_at' => now(),
            'scheduled_close_at' => now()->addHour(), 'quorum_percent' => 50,
        ]);
    }
}
