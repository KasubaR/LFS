<?php

namespace Tests\Feature;

use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class AdminPagesBatch1Test extends TestCase
{
    use ActsAsAdmin;

    public function test_login_page_renders_auth_layout(): void
    {
        $response = $this->get($this->adminLoginPath());

        $response->assertOk();
        $response->assertSee('admin-layout--auth', false);
        $response->assertSee('Admin Sign In', false);
        $response->assertSee('name="password"', false);
    }

    public function test_unauthenticated_dashboard_redirects_to_login(): void
    {
        $response = $this->get('/admin/dashboard');

        $response->assertRedirect($this->adminLoginPath());
    }

    public function test_login_blade_renders_with_minimal_data(): void
    {
        $html = view('admin.auth.login', [
            'pageTitle' => 'Admin Login',
            'activePage' => '',
            'authPage' => true,
            'error' => null,
        ])->render();

        $this->assertStringContainsString('admin-layout--auth', $html);
        $this->assertStringContainsString('Admin Sign In', $html);
    }
}
