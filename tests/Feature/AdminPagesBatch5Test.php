<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class AdminPagesBatch5Test extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    public function test_gallery_albums_renders(): void
    {
        $response = $this->actingAsAdmin()->get('/admin/gallery/albums');

        $response->assertOk();
        $response->assertSee('class="admin-sidebar"', false);
    }

    public function test_gallery_settings_renders(): void
    {
        $response = $this->actingAsAdmin()->get('/admin/gallery/settings');

        $response->assertOk();
    }

    public function test_gallery_manage_blade_renders_with_minimal_data(): void
    {
        $html = view('admin.gallery.manage', [
            'pageTitle' => 'Manage Album',
            'activePage' => 'gallery',
            'album' => ['id' => 'test-album', 'title' => 'Test', 'category' => 'Events'],
            'media' => [],
            'adminUser' => ['name' => 'Admin', 'email' => 'admin@test.com'],
            'counts' => ['pendingMembers' => 0, 'pendingOrders' => 0, 'pendingGallery' => 0, 'newMessages' => 0],
            'breadcrumbs' => [],
            'extraStyles' => '',
            'extraScripts' => '',
        ])->render();

        $this->assertStringContainsString('MEDIA_DATA', $html);
    }
}
