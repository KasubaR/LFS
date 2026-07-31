<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('Sign In', false);
        $response->assertSee('lfs-form', false);
    }

    public function test_users_can_authenticate_with_verified_email(): void
    {
        $user = User::factory()->create([
            'email' => 'member@example.com',
            'password' => 'password123',
        ]);

        $response = $this->post('/login', [
            'email' => 'member@example.com',
            'password' => 'password123',
        ]);

        $response->assertRedirect('/membership/apply');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->first_login);
    }

    public function test_unverified_users_are_redirected_to_email_verify(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'unverified@example.com',
            'password' => 'password123',
        ]);

        $this->post('/login', [
            'email' => 'unverified@example.com',
            'password' => 'password123',
        ]);

        $this->assertAuthenticatedAs($user);
        $response = $this->get('/account');
        $response->assertRedirect('/email/verify');
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);
        $response = $this->post('/logout');

        $response->assertRedirect('/');
        $this->assertGuest();
    }
}
