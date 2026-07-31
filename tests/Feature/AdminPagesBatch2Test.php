<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class AdminPagesBatch2Test extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    public function test_dashboard_renders_with_sidebar(): void
    {
        $response = $this->actingAsAdmin()->get('/admin/dashboard');

        $response->assertOk();
        $response->assertSee('class="admin-sidebar"', false);
        $response->assertSee('class="admin-main"', false);
        $response->assertSee('noindex', false);
    }

    public function test_activity_page_renders(): void
    {
        $response = $this->actingAsAdmin()->get('/admin/activity');

        $response->assertOk();
        $response->assertSee('Recent Activity', false);
    }

    public function test_dashboard_blade_renders_with_minimal_data(): void
    {
        $html = view('admin.dashboard.index', [
            'pageTitle' => 'Dashboard',
            'activePage' => 'dashboard',
            'adminUser' => ['name' => 'Admin', 'email' => 'admin@test.com'],
            'stats' => ['newMessages' => 0, 'pendingOrders' => 0, 'upcomingEvents' => 0, 'monthlyRevenue' => 0, 'galleryUploads' => 0, 'totalMembers' => 0],
            'counts' => ['pendingMembers' => 0, 'pendingOrders' => 0, 'pendingGallery' => 0, 'newMessages' => 0],
            'notifications' => ['unread' => 0, 'items' => []],
            'recentActivity' => [],
            'pendingTasks' => [],
            'chartData' => ['members' => [], 'events' => [], 'sales' => [], 'gallery' => []],
            'upcomingEvents' => [],
            'extraStyles' => '',
            'extraScripts' => '',
        ])->render();

        $this->assertStringContainsString('stats-grid', $html);
        $this->assertStringContainsString('stat-card--green', $html);
    }
}
