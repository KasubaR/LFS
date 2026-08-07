<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class AdminPagesBatch7Test extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    public function test_messages_index_renders(): void
    {
        $response = $this->actingAsAdmin()->get('/admin/messages');

        $response->assertOk();
        $response->assertSee('class="admin-sidebar"', false);
    }

    public function test_faqs_index_renders(): void
    {
        $response = $this->actingAsAdmin()->get('/admin/faqs');

        $response->assertOk();
    }

    public function test_members_list_renders(): void
    {
        $response = $this->actingAsAdmin()->get('/admin/members');

        $response->assertOk();
        $response->assertSee('Members', false);
        $response->assertSee('View all members', false);
    }

    public function test_members_index_breaks_out_suspended_from_inactive(): void
    {
        // Regression test: the stats row used to only compute
        // Total/Active/Pending/Inactive, so a Suspended member counted
        // towards Total but showed up in none of the other three cards.
        $members = [
            ['status' => 'active'],
            ['status' => 'pending'],
            ['status' => 'suspended'],
            ['status' => 'suspended'],
            ['status' => 'inactive'],
        ];

        $html = view('admin.members.index', [
            'pageTitle' => 'Members',
            'activePage' => 'members',
            'breadcrumbs' => [],
            'members' => $members,
            'chartData' => null,
        ])->render();

        $this->assertMatchesRegularExpression(
            '/Total.*?5.*?Active.*?1.*?Pending.*?1.*?Suspended.*?2.*?Inactive.*?1/s',
            $html,
            'Suspended must render as its own count, separate from Total/Active/Pending/Inactive'
        );
    }

    public function test_members_full_list_renders(): void
    {
        $response = $this->actingAsAdmin()->get('/admin/members/list');

        $response->assertOk();
        $response->assertSee('All Members', false);
        $response->assertSee('Import Members', false);
    }

    public function test_members_full_list_filters_by_status(): void
    {
        $response = $this->actingAsAdmin()->get('/admin/members/list?status=pending');

        $response->assertOk();
    }

    public function test_members_blade_renders_with_minimal_data(): void
    {
        $html = view('admin.members.list', [
            'pageTitle' => 'Members',
            'activePage' => 'members',
            'members' => [],
            'filterStatus' => '',
            'counts' => ['pendingMembers' => 0, 'pendingOrders' => 0, 'pendingGallery' => 0, 'newMessages' => 0],
            'breadcrumbs' => [],
            'extraStyles' => '',
            'extraScripts' => '',
        ])->render();

        $this->assertStringContainsString('Members', $html);
    }
}
