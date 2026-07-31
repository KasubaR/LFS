<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class AdminPagesBatch3Test extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    public function test_events_list_renders(): void
    {
        $response = $this->actingAsAdmin()->get('/admin/events/list');

        $response->assertOk();
        $response->assertSee('class="admin-sidebar"', false);
    }

    public function test_events_create_form_renders(): void
    {
        $response = $this->actingAsAdmin()->get('/admin/events/create');

        $response->assertOk();
        $response->assertSee('lfsDistanceRows', false);
    }

    public function test_event_form_blade_renders_with_minimal_data(): void
    {
        $html = view('admin.events.event-form', [
            'pageTitle' => 'New Event',
            'activePage' => 'events',
            'isEdit' => false,
            'event' => null,
            'adminUser' => ['name' => 'Admin', 'email' => 'admin@test.com'],
            'counts' => ['pendingMembers' => 0, 'pendingOrders' => 0, 'pendingGallery' => 0, 'newMessages' => 0],
            'breadcrumbs' => [],
            'extraStyles' => '',
            'extraScripts' => '',
        ])->render();

        $this->assertStringContainsString('lfsDistanceRows', $html);
        $this->assertStringContainsString('bannerImageFile', $html);
    }
}
