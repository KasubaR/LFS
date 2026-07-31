<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicPagesBatch2Test extends TestCase
{
    public function test_news_listing_renders_with_layout_and_content(): void
    {
        $response = $this->get('/news');

        $response->assertOk();
        $response->assertSee('class="lfs-nav"', false);
        $response->assertSee('News &amp; Updates', false);
        $response->assertSee('class="blog-layout"', false);
        $response->assertSee('class="blog-hero"', false);
    }

    public function test_news_search_query_renders(): void
    {
        $response = $this->get('/news?q=test');

        $response->assertOk();
        $response->assertSee('class="blog-search"', false);
        $response->assertSee('value="test"', false);
    }

    public function test_missing_news_post_returns_blade_404_page(): void
    {
        $response = $this->get('/news/nonexistent-slug-batch2');

        $response->assertNotFound();
        $response->assertSee('Page Not Found', false);
    }

    public function test_news_blade_view_renders_with_minimal_data(): void
    {
        $html = view('pages.news', [
            'title' => 'News & Updates — LFS',
            'description' => 'Test description',
            'page' => 'news',
            'posts' => [],
            'featured' => null,
            'activeCategory' => '',
            'searchQuery' => '',
            'currentPage' => 1,
            'totalPages' => 1,
            'categories' => ['Club News'],
            'extraStyles' => '',
            'extraScripts' => '',
        ])->render();

        $this->assertStringContainsString('class="blog-hero"', $html);
        $this->assertStringContainsString('News &amp; Updates', $html);
    }
}
