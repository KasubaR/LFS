<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicPagesBatch6Test extends TestCase
{
    public function test_home_page_renders_with_layout_and_key_sections(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('class="lfs-nav"', false);
        $response->assertSee('id="hero"', false);
        $response->assertSee('id="about"', false);
        $response->assertSee('id="events"', false);
        $response->assertSee('id="membership"', false);
        $response->assertSee('id="shop"', false);
        $response->assertSee('id="news"', false);
    }

    public function test_home_page_renders_hero_markup(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('home-hero--compact', false);
        $response->assertSee('id="heroSliderBg"', false);
        $response->assertSee('Zambia\'s Biggest', false);
    }

    public function test_home_blade_view_renders_with_minimal_data(): void
    {
        $html = view('pages.home', [
            'title' => 'Home',
            'description' => 'Test description',
            'page' => 'home',
            'galleryPreview' => [],
            'heroSlides' => [],
            'heroFeaturedEvents' => [],
            'events' => [],
            'products' => [],
            'posts' => [],
            'heroImage' => '/images/home/home-hero.jpg',
            'extraStyles' => '',
            'extraScripts' => '',
        ])->render();

        $this->assertStringContainsString('home-hero--compact', $html);
        $this->assertStringContainsString('Zambia\'s Biggest', $html);
        $this->assertStringContainsString('No Upcoming Events', $html);
        $this->assertStringContainsString('No stories yet', $html);
        $this->assertStringContainsString('No products yet', $html);
    }
}
