<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class AdminPagesBatch4Test extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    public function test_blog_list_renders(): void
    {
        $response = $this->actingAsAdmin()->get('/admin/blog/list');

        $response->assertOk();
        $response->assertSee('class="admin-sidebar"', false);
    }

    public function test_blog_create_renders_editor_form(): void
    {
        $response = $this->actingAsAdmin()->get('/admin/blog/create');

        $response->assertOk();
        $response->assertSee('quill.snow.css', false);
        $response->assertSee('blog-form.js', false);
    }

    public function test_blog_create_blade_renders_with_minimal_data(): void
    {
        $html = view('admin.blog.create', [
            'pageTitle' => 'New Post',
            'activePage' => 'blog',
            'post' => null,
            'categories' => [],
            'breadcrumbs' => [],
            'extraStyles' => '',
            'extraScripts' => '',
        ])->render();

        $this->assertStringContainsString('blog-editor-page', $html);
    }
}
