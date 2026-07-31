<?php

namespace Tests\Feature;

use Tests\TestCase;

class BladeLayoutTest extends TestCase
{
    public function test_app_layout_renders_navbar_footer_and_main_content(): void
    {
        $response = $this->view('_phase1-smoke', [
            'title' => 'Smoke Test',
            'description' => 'Phase 1 layout verification',
        ]);

        $response->assertSee('class="lfs-nav"', false);
        $response->assertSee('class="lfs-footer"', false);
        $response->assertSee('<main id="main-content">', false);
        $response->assertSee('Phase 1 smoke', false);
    }

    public function test_pagination_partial_renders_page_links(): void
    {
        $response = $this->view('partials.pagination', [
            'currentPage' => 2,
            'totalPages' => 5,
            'baseUrl' => '/news?page=',
        ]);

        $response->assertSee('class="lfs-pagination"', false);
        $response->assertSee('aria-current="page"', false);
        $response->assertSee('/news?page=1', false);
        $response->assertSee('/news?page=3', false);
    }
}
