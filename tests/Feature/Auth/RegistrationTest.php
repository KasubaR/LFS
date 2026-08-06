<?php

namespace Tests\Feature\Auth;

use App\Models\Membership;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SatelliteSeeder::class);
        $this->seed(MembershipPlanSeeder::class);
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/create-account');

        $response->assertOk();
        $response->assertSee('Create Account', false);
        $response->assertSee('lfs-form', false);
        $response->assertDontSee('Membership plan', false);
    }

    public function test_new_users_can_register_without_membership(): void
    {
        Notification::fake();

        $response = $this->post('/create-account', [
            'last_name' => 'Runner',
            'other_names' => 'Jane',
            'email' => 'jane@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'phone' => '0977000000',
            'gender' => 'female',
            'nationality' => 'Zambian',
            't_shirt_size' => 'M',
            'town' => 'Lusaka',
        ]);

        $response->assertRedirect('/email/verify');
        $this->assertAuthenticated();

        $user = User::query()->where('email', 'jane@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->satellite_id);
        $this->assertFalse($user->must_change_password);
        $this->assertSame('female', $user->gender);
        $this->assertSame('Zambian', $user->nationality);
        $this->assertSame('M', $user->t_shirt_size);
        $this->assertSame('Lusaka', $user->town);
        $this->assertNotNull($user->registered_at);

        $this->assertNull(Membership::query()->where('user_id', $user->id)->first());

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }
}
