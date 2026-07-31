<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicPagesBatch5Test extends TestCase
{
    public function test_contact_page_renders_with_layout_and_content(): void
    {
        $response = $this->get('/contact');

        $response->assertOk();
        $response->assertSee('class="lfs-nav"', false);
        $response->assertSee('Contact Us', false);
        $response->assertSee('class="page-header"', false);
        $response->assertSee('id="contact"', false);
        $response->assertSee('id="satellites"', false);
    }

    public function test_contact_page_renders_form_with_csrf_token(): void
    {
        $response = $this->get('/contact');

        $response->assertOk();
        $response->assertSee('name="_token"', false);
        $response->assertSee('Send Us a Message', false);
    }

    public function test_contact_blade_view_renders_with_minimal_data(): void
    {
        $html = view('pages.contact-us', [
            'title' => 'Contact Us',
            'description' => 'Test description',
            'page' => 'contact',
            'bodyClass' => 'page-no-hero page-contact',
            'faqs' => [],
            'submitted' => false,
            'errors' => [],
            'old' => [],
        ])->render();

        $this->assertStringContainsString('class="page-header"', $html);
        $this->assertStringContainsString('Send Us a Message', $html);
        $this->assertStringContainsString('id="satellites"', $html);
    }
}
