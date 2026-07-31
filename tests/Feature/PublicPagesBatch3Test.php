<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicPagesBatch3Test extends TestCase
{
    public function test_events_listing_renders_with_layout_and_content(): void
    {
        $response = $this->get('/events');

        $response->assertOk();
        $response->assertSee('class="lfs-nav"', false);
        $response->assertSee('Events &amp; Races', false);
        $response->assertSee('class="events-header"', false);
        $response->assertSee('id="upcoming"', false);
    }

    public function test_missing_event_redirects_to_events_index(): void
    {
        $response = $this->get('/events/nonexistent-slug-batch3');

        $response->assertRedirect('/events');
    }

    public function test_events_blade_view_renders_with_minimal_data(): void
    {
        $html = view('pages.events', [
            'title' => 'Events & Races',
            'description' => 'Test description',
            'page' => 'events',
            'events' => [],
            'bodyClass' => 'page-no-hero',
            'extraStyles' => '',
            'extraScripts' => '',
        ])->render();

        $this->assertStringContainsString('class="events-header"', $html);
        $this->assertStringContainsString('Events &amp; Races', $html);
    }

    public function test_event_details_blade_view_renders_with_minimal_data(): void
    {
        $html = view('pages.event-details', [
            'title' => 'Test Event',
            'description' => 'Test event description',
            'page' => 'events',
            'event' => [
                'title' => 'Test Event',
                'slug' => 'test-event-batch3',
                'description' => 'A test event for Batch 3.',
            ],
            'extraStyles' => '',
            'extraScripts' => '',
        ])->render();

        $this->assertStringContainsString('class="event-detail-hero"', $html);
        $this->assertStringContainsString('Test Event', $html);
    }
}
