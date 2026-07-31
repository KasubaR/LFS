<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicPagesBatch4Test extends TestCase
{
    public function test_gallery_listing_renders_with_layout_and_content(): void
    {
        $response = $this->get('/gallery');

        $response->assertOk();
        $response->assertSee('class="lfs-nav"', false);
        $response->assertSee('Photo Albums', false);
        $response->assertSee('class="gallery-header"', false);
        $response->assertSee('id="albums"', false);
    }

    public function test_missing_gallery_album_returns_blade_404_page(): void
    {
        $response = $this->get('/gallery/nonexistent-album-batch4');

        $response->assertNotFound();
        $response->assertSee('Album not found', false);
    }

    public function test_gallery_blade_view_renders_with_minimal_data(): void
    {
        $html = view('pages.gallery', [
            'title' => 'Gallery',
            'description' => 'Test description',
            'albums' => [],
            'galleryError' => null,
            'fallbackMedia' => [],
            'galleryBanner' => null,
            'extraStyles' => '',
        ])->render();

        $this->assertStringContainsString('class="gallery-header"', $html);
        $this->assertStringContainsString('Photo Albums', $html);
    }

    public function test_gallery_album_blade_view_renders_with_minimal_data(): void
    {
        $html = view('pages.gallery-album', [
            'title' => 'Test Album',
            'description' => 'Test album description',
            'album' => [
                'title' => 'Test Album',
                '_id' => 'test-batch4',
            ],
            'media' => [],
            'extraStyles' => '',
            'extraScripts' => '',
        ])->render();

        $this->assertStringContainsString('class="event-detail-hero"', $html);
        $this->assertStringContainsString('Test Album', $html);
        $this->assertStringContainsString('id="lbOverlay"', $html);
    }
}
