<?php

namespace Tests\Feature\Api;

use App\Enums\ApiScope;
use App\Enums\ApprovalStatus;
use App\Enums\BillingCycle;
use App\Enums\MembershipStatus;
use App\Models\ApiClient;
use App\Models\ApiRequestLog;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Satellite;
use App\Models\User;
use App\Services\ApiClientService;
use App\Support\Uuid;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MemberVerificationApiTest extends TestCase
{
    use RefreshDatabase;

    private const VERIFY_URL = '/api/v1/members/verify';

    private MembershipPlan $plan;

    private Satellite $satellite;

    private string $token;

    private ApiClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SatelliteSeeder::class);
        $this->seed(MembershipPlanSeeder::class);

        $this->satellite = Satellite::query()->where('slug', 'woodies')->first();
        $this->plan = MembershipPlan::query()->where('billing_cycle', BillingCycle::Annual)->first();

        $issued = app(ApiClientService::class)->create([
            'name' => 'Lusaka Marathon 2026',
            'scopes' => [ApiScope::MembersVerify, ApiScope::MembersReadToken],
        ]);

        $this->client = $issued['client'];
        $this->token = $issued['plainToken'];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeMembership(array $overrides = [], string $name = 'Chanda Mwale', string $email = 'chanda@example.com'): Membership
    {
        // Same last-word-is-surname split the app itself uses (see
        // MemberImportService::splitFullName), so $user->name reconstructs
        // to exactly $name and multi-word surnames still round-trip.
        $parts = explode(' ', trim($name));
        $lastName = array_pop($parts);
        $otherNames = implode(' ', $parts);

        $user = User::factory()->create([
            'last_name' => $lastName,
            'other_names' => $otherNames,
            'email' => $email,
            'satellite_id' => $this->satellite->id,
        ]);

        return Membership::query()->create(array_merge([
            'id' => Uuid::v4(),
            'user_id' => $user->id,
            'membership_number' => 'LFS-000412',
            'public_token' => Uuid::v4(),
            'status' => MembershipStatus::Active,
            'start_date' => now()->subMonths(2)->toDateString(),
            'expiry_date' => now()->addMonths(10)->toDateString(),
            'current_plan_id' => $this->plan->id,
            'approval_status' => ApprovalStatus::Approved,
            'joined_at' => now()->subMonths(2),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function verify(array $payload, ?string $token = null): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.($token ?? $this->token))
            ->postJson(self::VERIFY_URL, $payload);
    }

    // ── Authentication ───────────────────────────────────────────────────────

    public function test_request_without_credentials_is_rejected(): void
    {
        $this->postJson(self::VERIFY_URL, ['membership_number' => 'LFS-000412', 'surname' => 'Mwale'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthorized');
    }

    public function test_request_with_unknown_key_is_rejected(): void
    {
        $this->verify(['membership_number' => 'LFS-000412', 'surname' => 'Mwale'], 'lfsk_deadbeefdeadbeef.nope')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthorized');
    }

    public function test_request_with_wrong_secret_is_rejected(): void
    {
        $keyId = explode('.', $this->token)[0];

        $this->verify(['membership_number' => 'LFS-000412', 'surname' => 'Mwale'], $keyId.'.wrongsecret')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthorized');
    }

    public function test_revoked_key_is_rejected(): void
    {
        app(ApiClientService::class)->revoke($this->client->id);

        $this->verify(['membership_number' => 'LFS-000412', 'surname' => 'Mwale'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'credential_revoked');
    }

    public function test_expired_key_is_rejected(): void
    {
        $this->client->forceFill(['expires_at' => now()->subDay()])->save();

        $this->verify(['membership_number' => 'LFS-000412', 'surname' => 'Mwale'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'credential_expired');
    }

    public function test_ip_allowlist_blocks_other_addresses(): void
    {
        $this->client->forceFill(['allowed_ips' => ['203.0.113.9']])->save();

        $this->verify(['membership_number' => 'LFS-000412', 'surname' => 'Mwale'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ip_not_allowed');
    }

    public function test_key_without_scope_is_rejected(): void
    {
        $this->client->forceFill(['scopes' => [ApiScope::MembersReadToken]])->save();

        $this->verify(['membership_number' => 'LFS-000412', 'surname' => 'Mwale'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'insufficient_scope');
    }

    public function test_endpoint_requires_no_session(): void
    {
        $this->makeMembership();

        // No session, no CSRF token, no admin login — proves the route is
        // outside the `web` middleware group.
        $this->verify(['membership_number' => 'LFS-000412', 'surname' => 'Mwale'])
            ->assertOk()
            ->assertJsonPath('data.is_member', true);
    }

    // ── Verification outcomes ────────────────────────────────────────────────

    public function test_active_member_verifies_by_surname(): void
    {
        $this->makeMembership();

        $this->verify(['membership_number' => 'LFS-000412', 'surname' => 'Mwale'])
            ->assertOk()
            ->assertJsonPath('data.is_member', true)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.membership_number', 'LFS-000412')
            ->assertJsonPath('data.first_name', 'Chanda');
    }

    public function test_active_member_verifies_by_email(): void
    {
        $this->makeMembership();

        $this->verify(['membership_number' => 'LFS-000412', 'email' => 'CHANDA@example.com'])
            ->assertOk()
            ->assertJsonPath('data.is_member', true)
            ->assertJsonPath('data.status', 'active');
    }

    public function test_surname_match_is_case_and_space_insensitive(): void
    {
        $this->makeMembership();

        $this->verify(['membership_number' => '  lfs-000412 ', 'surname' => '  mWaLe '])
            ->assertOk()
            ->assertJsonPath('data.is_member', true);
    }

    public function test_undashed_legacy_membership_number_verifies_by_surname(): void
    {
        // Legacy bulk-imported members have an undashed number like
        // "LFS14149" (see MemberImportService/member-import:backfill-lfs-prefix).
        $this->makeMembership(['membership_number' => 'LFS14149']);

        $this->verify(['membership_number' => 'LFS14149', 'surname' => 'Mwale'])
            ->assertOk()
            ->assertJsonPath('data.is_member', true)
            ->assertJsonPath('data.membership_number', 'LFS14149');
    }

    public function test_bare_number_without_lfs_prefix_still_verifies(): void
    {
        // A partner (or member) who only knows the number as originally
        // printed, before it was backfilled with a prefix, shouldn't be
        // turned away.
        $this->makeMembership(['membership_number' => 'LFS14149']);

        $this->verify(['membership_number' => '14149', 'surname' => 'Mwale'])
            ->assertOk()
            ->assertJsonPath('data.is_member', true)
            ->assertJsonPath('data.membership_number', 'LFS14149');
    }

    public function test_multi_word_surname_is_accepted(): void
    {
        $this->makeMembership(name: 'Pieter van der Merwe', email: 'pieter@example.com');

        $this->verify(['membership_number' => 'LFS-000412', 'surname' => 'van der Merwe'])
            ->assertOk()
            ->assertJsonPath('data.is_member', true);
    }

    public function test_wrong_surname_does_not_reveal_that_the_number_exists(): void
    {
        $this->makeMembership();

        // Identical to an unknown-number response: no membership_number echoed,
        // no first name, no status leak.
        $this->verify(['membership_number' => 'LFS-000412', 'surname' => 'Banda'])
            ->assertOk()
            ->assertJsonPath('data.is_member', false)
            ->assertJsonPath('data.status', 'not_found')
            ->assertJsonPath('data.membership_number', null)
            ->assertJsonPath('data.first_name', null);
    }

    public function test_unknown_membership_number_returns_not_found_with_200(): void
    {
        $this->verify(['membership_number' => 'LFS-999999', 'surname' => 'Mwale'])
            ->assertOk()
            ->assertJsonPath('data.is_member', false)
            ->assertJsonPath('data.status', 'not_found');
    }

    public function test_expired_membership_reports_expired(): void
    {
        $this->makeMembership([
            'status' => MembershipStatus::Expired,
            'expiry_date' => now()->subMonth()->toDateString(),
        ]);

        $this->verify(['membership_number' => 'LFS-000412', 'surname' => 'Mwale'])
            ->assertOk()
            ->assertJsonPath('data.is_member', false)
            ->assertJsonPath('data.status', 'expired');
    }

    public function test_cancelled_membership_reports_cancelled(): void
    {
        $this->makeMembership(['status' => MembershipStatus::Cancelled]);

        $this->verify(['membership_number' => 'LFS-000412', 'surname' => 'Mwale'])
            ->assertOk()
            ->assertJsonPath('data.is_member', false)
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_unpaid_application_reports_pending_payment(): void
    {
        $this->makeMembership([
            'status' => MembershipStatus::PendingPayment,
            'expiry_date' => null,
        ]);

        $this->verify(['membership_number' => 'LFS-000412', 'surname' => 'Mwale'])
            ->assertOk()
            ->assertJsonPath('data.is_member', false)
            ->assertJsonPath('data.status', 'pending_payment');
    }

    public function test_suspended_membership_reports_pending_payment_not_expired(): void
    {
        // Suspended still belongs to the current membership year — it needs
        // its outstanding balance paid, not a fresh renewal — so it must not
        // be lumped in with a genuinely Expired membership (see
        // MemberVerificationService::statusFor()).
        $this->makeMembership([
            'status' => MembershipStatus::Suspended,
            'expiry_date' => now()->addMonths(6)->toDateString(),
        ]);

        $this->verify(['membership_number' => 'LFS-000412', 'surname' => 'Mwale'])
            ->assertOk()
            ->assertJsonPath('data.is_member', false)
            ->assertJsonPath('data.status', 'pending_payment');
    }

    public function test_active_status_past_expiry_reports_expired(): void
    {
        // Row never swept by membership:expire — the date is what counts.
        $this->makeMembership([
            'status' => MembershipStatus::Active,
            'expiry_date' => now()->subDay()->toDateString(),
        ]);

        $this->verify(['membership_number' => 'LFS-000412', 'surname' => 'Mwale'])
            ->assertOk()
            ->assertJsonPath('data.is_member', false)
            ->assertJsonPath('data.status', 'expired');
    }

    public function test_membership_expiring_today_is_still_active(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-31 14:00:00'));

        $this->makeMembership(['expiry_date' => '2026-07-31']);

        $this->verify(['membership_number' => 'LFS-000412', 'surname' => 'Mwale'])
            ->assertOk()
            ->assertJsonPath('data.is_member', true)
            ->assertJsonPath('data.status', 'active');

        Carbon::setTestNow();
    }

    public function test_renewal_row_sharing_a_number_resolves_to_the_active_one(): void
    {
        // startRenewal() reuses membership_number on a new row, so a number can
        // match several memberships.
        $expired = $this->makeMembership([
            'status' => MembershipStatus::Expired,
            'expiry_date' => now()->subMonths(2)->toDateString(),
            'public_token' => null,
        ]);

        Membership::query()->create([
            'id' => Uuid::v4(),
            'user_id' => $expired->user_id,
            'membership_number' => 'LFS-000412',
            'public_token' => Uuid::v4(),
            'status' => MembershipStatus::Active,
            'start_date' => now()->subDay()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
            'current_plan_id' => $this->plan->id,
            'approval_status' => ApprovalStatus::Approved,
        ]);

        $this->verify(['membership_number' => 'LFS-000412', 'surname' => 'Mwale'])
            ->assertOk()
            ->assertJsonPath('data.is_member', true)
            ->assertJsonPath('data.status', 'active');
    }

    // ── Validation ───────────────────────────────────────────────────────────

    public function test_membership_number_alone_is_rejected(): void
    {
        $this->makeMembership();

        // Numbers are sequential and guessable; a second factor is mandatory.
        $this->verify(['membership_number' => 'LFS-000412'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_request');
    }

    public function test_missing_membership_number_is_rejected(): void
    {
        $this->verify(['surname' => 'Mwale'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_request');
    }

    public function test_malformed_email_is_rejected(): void
    {
        $this->verify(['membership_number' => 'LFS-000412', 'email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_request');
    }

    public function test_providing_both_surname_and_email_is_rejected(): void
    {
        $this->makeMembership();

        $this->verify([
            'membership_number' => 'LFS-000412',
            'surname' => 'Mwale',
            'email' => 'chanda@example.com',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_request');
    }

    // ── Token lookup ─────────────────────────────────────────────────────────

    public function test_card_token_lookup_returns_member(): void
    {
        $membership = $this->makeMembership();

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/members/token/'.$membership->public_token)
            ->assertOk()
            ->assertJsonPath('data.is_member', true)
            ->assertJsonPath('data.status', 'active');
    }

    public function test_unknown_card_token_returns_not_found_payload(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/members/token/'.Uuid::v4())
            ->assertOk()
            ->assertJsonPath('data.is_member', false)
            ->assertJsonPath('data.status', 'not_found');
    }

    // ── Rate limiting & audit ────────────────────────────────────────────────

    public function test_client_rate_limit_returns_429(): void
    {
        $this->client->forceFill(['rate_limit_per_minute' => 3])->save();
        $this->makeMembership();

        for ($i = 0; $i < 3; $i++) {
            $this->verify(['membership_number' => 'LFS-000412', 'surname' => 'Mwale'])->assertOk();
        }

        $this->verify(['membership_number' => 'LFS-000412', 'surname' => 'Mwale'])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'rate_limited')
            ->assertHeader('Retry-After');
    }

    public function test_requests_are_written_to_the_audit_log(): void
    {
        $this->makeMembership();

        $this->verify(['membership_number' => 'LFS-000412', 'surname' => 'Mwale'])->assertOk();

        $log = ApiRequestLog::query()->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame($this->client->id, $log->api_client_id);
        $this->assertSame('api/v1/members/verify', $log->path);
        $this->assertSame(200, $log->status);
        $this->assertSame('active', $log->result);
    }

    public function test_unauthenticated_requests_are_also_logged(): void
    {
        $this->postJson(self::VERIFY_URL, ['membership_number' => 'LFS-000412', 'surname' => 'Mwale'])
            ->assertStatus(401);

        $log = ApiRequestLog::query()->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertNull($log->api_client_id);
        $this->assertSame(401, $log->status);
        $this->assertSame('unauthorized', $log->result);
    }
}
