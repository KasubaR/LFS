<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicPagesBatch1Test extends TestCase
{
    public function test_about_page_renders_with_layout_and_content(): void
    {
        $response = $this->get('/about');

        $response->assertOk();
        $response->assertSee('class="lfs-nav"', false);
        $response->assertSee('class="lfs-footer"', false);
        $response->assertSee('About Lusaka Fitness Squad', false);
        $response->assertSee('id="satellites"', false);
    }

    public function test_privacy_page_renders_with_layout_and_content(): void
    {
        $response = $this->get('/privacy');

        $response->assertOk();
        $response->assertSee('class="lfs-nav"', false);
        $response->assertSee('Privacy Policy', false);
    }

    public function test_fallback_route_returns_blade_404_page(): void
    {
        $response = $this->get('/nonexistent-route-xyz-batch1');

        $response->assertNotFound();
        $response->assertSee('Page Not Found', false);
        $response->assertSee('class="lfs-nav"', false);
    }

    public function test_missing_news_post_returns_blade_404_page(): void
    {
        $response = $this->get('/news/nonexistent-slug-batch1');

        $response->assertNotFound();
        $response->assertSee('Page Not Found', false);
    }
}
