<?php

namespace Tests\Feature\Admin;

use App\Models\Membership;
use App\Models\MembershipImportBatch;
use App\Models\MembershipImportRecord;
use App\Models\MembershipPayment;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Services\MemberImportService;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class MemberImportTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SatelliteSeeder::class);
        $this->seed(MembershipPlanSeeder::class);
        $this->fixturePath = base_path('tests/fixtures/member-import-sample.csv');
    }

    public function test_import_creates_active_membership_with_ref_number(): void
    {
        $service = app(MemberImportService::class);
        $result = $service->importFromFile($this->fixturePath, 'test:import');

        $this->assertSame(3, $result['totalRows']);
        $this->assertSame(2, $result['importedRows']);
        $this->assertSame(1, $result['skippedRows']);

        $alice = User::query()->where('email', 'alice.import@test.com')->first();
        $this->assertNotNull($alice);
        $this->assertTrue($alice->must_change_password);
        $this->assertSame('M', $alice->t_shirt_size);
        $this->assertSame('Lusaka', $alice->town);
        $this->assertNotNull($alice->registered_at);

        $membership = Membership::query()->where('user_id', $alice->id)->first();
        $this->assertNotNull($membership);
        $this->assertSame('13239', $membership->membership_number);
        $this->assertSame('active', $membership->status->value ?? $membership->status);

        $payment = MembershipPayment::query()->where('membership_id', $membership->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame('paid', $payment->status->value ?? $payment->status);
        $this->assertSame('import', $payment->payment_gateway);
    }

    public function test_import_parses_comma_formatted_net_amount(): void
    {
        // Regression test: the spreadsheet's "Net amount" column is sometimes text
        // formatted with a thousands separator (e.g. "1,000.00"). PHP's (float) cast
        // truncates at the comma, so this used to silently import the payment as 1.00
        // instead of 1000.00.
        $service = app(MemberImportService::class);
        $result = $service->importFromFile(base_path('tests/fixtures/member-import-comma-amount.csv'), 'test:import');

        $this->assertSame(1, $result['importedRows']);

        $dana = User::query()->where('email', 'dana.import@test.com')->first();
        $this->assertNotNull($dana);

        $membership = Membership::query()->where('user_id', $dana->id)->first();
        $payment = MembershipPayment::query()->where('membership_id', $membership->id)->first();

        $this->assertNotNull($payment);
        $this->assertSame('1000.00', $payment->amount);
        $this->assertSame('1000.00', $payment->amount_paid);
    }

    public function test_rollback_removes_imported_records(): void
    {
        $service = app(MemberImportService::class);
        $result = $service->importFromFile($this->fixturePath, 'test:import');

        $batchId = $result['batchId'];
        $this->assertSame(2, MembershipImportRecord::query()->where('batch_id', $batchId)->count());

        $service->rollbackBatch($batchId);

        $this->assertSame('rolled_back', MembershipImportBatch::query()->find($batchId)->status);
        $this->assertSame(0, User::query()->where('email', 'alice.import@test.com')->count());
        $this->assertSame(0, User::query()->where('email', 'bob.import@test.com')->count());
    }

    public function test_import_skips_duplicate_membership_numbers(): void
    {
        Membership::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => User::factory()->create()->id,
            'membership_number' => '13239',
            'status' => 'active',
            'current_plan_id' => \App\Models\MembershipPlan::query()->first()->id,
            'approval_status' => 'approved',
        ]);

        $result = app(MemberImportService::class)->importFromFile($this->fixturePath, 'test:import');

        $this->assertSame(1, $result['importedRows']);
        $this->assertTrue(
            collect($result['errors'])->contains(fn ($msg) => str_contains($msg, 'membership number 13239 already exists'))
        );
    }

    public function test_import_uses_shared_temp_password_and_sets_expiry(): void
    {
        $result = app(MemberImportService::class)->importFromFile($this->fixturePath, 'test:import');

        $this->assertNotEmpty($result['tempPasswordExpiresAt']);

        $alice = User::query()->where('email', 'alice.import@test.com')->first();
        $this->assertNotNull($alice);
        $this->assertNotNull($alice->temp_password_expires_at);
        $this->assertTrue(Hash::check(config('member_import.temp_password'), $alice->password));

        // The shared password lives only in config — it's never written into batch
        // notes or anywhere else per-row.
        $batch = MembershipImportBatch::query()->find($result['batchId']);
        $notes = $batch->notes ?? [];
        $this->assertArrayNotHasKey('tempPasswords', $notes);
    }

    public function test_import_requires_a_configured_temp_password(): void
    {
        config(['member_import.temp_password' => '']);

        $this->expectException(RuntimeException::class);

        app(MemberImportService::class)->importFromFile($this->fixturePath, 'test:import');
    }

    public function test_import_does_not_send_verification_email_immediately(): void
    {
        // The verification link expires long before most imported members
        // actually log in with their shared temp password (see
        // MemberImportService), so it must NOT be sent at import time —
        // otherwise every member gets a dead link before they've even tried
        // it. It's sent on first login instead (see the auth-flow test below).
        Notification::fake();

        app(MemberImportService::class)->importFromFile($this->fixturePath, 'test:import');

        $alice = User::query()->where('email', 'alice.import@test.com')->first();
        $this->assertNotNull($alice);

        Notification::assertNotSentTo($alice, VerifyEmailNotification::class);
    }

    public function test_first_login_sends_a_fresh_verification_email(): void
    {
        Notification::fake();

        app(MemberImportService::class)->importFromFile($this->fixturePath, 'test:import');

        $tempPassword = config('member_import.temp_password');
        $alice = User::query()->where('email', 'alice.import@test.com')->first();

        $this->post('/login', [
            'email' => 'alice.import@test.com',
            'password' => $tempPassword,
        ])->assertRedirect('/email/verify');

        Notification::assertSentTo($alice, VerifyEmailNotification::class);
        $this->assertNotNull($alice->fresh()->first_login);
    }

    public function test_imported_user_can_complete_auth_flow(): void
    {
        $service = app(MemberImportService::class);
        $service->importFromFile($this->fixturePath, 'test:import');

        $tempPassword = config('member_import.temp_password');
        $user = User::query()->where('email', 'alice.import@test.com')->first();
        $this->assertNotNull($user);

        $this->post('/login', [
            'email' => 'alice.import@test.com',
            'password' => $tempPassword,
        ])->assertRedirect('/email/verify');

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $this->actingAs($user)
            ->get($verificationUrl)
            ->assertRedirect('/password/change');

        $newPassword = 'SecureNew-Pass1';

        $this->actingAs($user->fresh())
            ->post('/password/change', [
                'current_password' => $tempPassword,
                'password' => $newPassword,
                'password_confirmation' => $newPassword,
            ])
            ->assertRedirect('/account');

        $this->actingAs($user->fresh())
            ->get('/account')
            ->assertOk()
            ->assertSee('13239', false);

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check($newPassword, $fresh->password));
        $this->assertFalse($fresh->must_change_password);
        $this->assertNull($fresh->temp_password_expires_at);
    }

    public function test_expired_shared_temp_password_blocks_imported_user_login(): void
    {
        $service = app(MemberImportService::class);
        $service->importFromFile($this->fixturePath, 'test:import');

        $user = User::query()->where('email', 'alice.import@test.com')->first();
        $user->forceFill(['temp_password_expires_at' => now()->subHour()])->save();

        $response = $this->post('/login', [
            'email' => 'alice.import@test.com',
            'password' => config('member_import.temp_password'),
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_admin_import_page_can_be_rendered(): void
    {
        $response = $this->actingAsAdmin()->get('/admin/members/import');

        $response->assertOk();
        $response->assertSee('Import Members', false);
    }
}
