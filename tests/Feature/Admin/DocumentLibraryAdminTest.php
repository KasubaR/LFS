<?php

namespace Tests\Feature\Admin;

use App\Enums\DocumentCategory;
use App\Models\Document;
use App\Support\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class DocumentLibraryAdminTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $dir = public_path('files/documents');
        if (is_dir($dir)) {
            foreach (glob($dir.DIRECTORY_SEPARATOR.'doc_*') ?: [] as $file) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_admin_can_list_documents_page(): void
    {
        $response = $this->actingAsAdmin()->get('/admin/documents');

        $response->assertOk();
        $response->assertSee('Document Library', false);
        $response->assertSee('Upload Document', false);
    }

    public function test_admin_can_create_document_with_upload(): void
    {
        $file = UploadedFile::fake()->create('constitution.pdf', 120, 'application/pdf');

        $response = $this->actingAsAdmin()->post('/admin/documents/create', [
            'title' => 'LFS Constitution',
            'category' => DocumentCategory::Constitution,
            'description' => 'Official club constitution',
            'is_published' => '1',
            'sort_order' => 1,
            'published_at' => now()->format('Y-m-d\TH:i'),
            'file' => $file,
        ]);

        $response->assertRedirect('/admin/documents');

        $document = Document::query()->first();
        $this->assertNotNull($document);
        $this->assertSame('LFS Constitution', $document->title);
        $this->assertSame(DocumentCategory::Constitution, $document->category);
        $this->assertTrue($document->is_published);
        $this->assertStringStartsWith('/files/documents/', $document->file_path);
        $this->assertFileExists(public_path(ltrim($document->file_path, '/')));
    }

    public function test_admin_create_requires_file(): void
    {
        $response = $this->actingAsAdmin()->from('/admin/documents/create')->post('/admin/documents/create', [
            'title' => 'Missing file',
            'category' => DocumentCategory::Policies,
            'is_published' => '1',
        ]);

        $response->assertRedirect('/admin/documents/create');
        $response->assertSessionHasErrors('file');
        $this->assertSame(0, Document::query()->count());
    }

    public function test_admin_can_update_and_delete_document(): void
    {
        $document = $this->seedDocument();

        $response = $this->actingAsAdmin()->post('/admin/documents/'.$document->id.'/edit', [
            'title' => 'Updated Policy',
            'category' => DocumentCategory::Policies,
            'description' => 'Revised',
            'is_published' => '1',
            'sort_order' => 2,
            'published_at' => now()->format('Y-m-d\TH:i'),
        ]);

        $response->assertRedirect('/admin/documents');
        $this->assertSame('Updated Policy', $document->fresh()->title);

        $path = public_path(ltrim($document->file_path, '/'));
        $this->assertFileExists($path);

        $delete = $this->actingAsAdmin()->post('/admin/documents/'.$document->id.'/delete');
        $delete->assertRedirect('/admin/documents');
        $this->assertNull(Document::query()->find($document->id));
        $this->assertFileDoesNotExist($path);
    }

    private function seedDocument(array $overrides = []): Document
    {
        $dir = public_path('files/documents');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $filename = 'doc_'.uniqid('', true).'.pdf';
        $full = $dir.DIRECTORY_SEPARATOR.$filename;
        File::put($full, '%PDF-1.4 test');

        return Document::query()->create(array_merge([
            'id' => Uuid::v4(),
            'title' => 'Sample Doc',
            'category' => DocumentCategory::Forms,
            'description' => null,
            'file_path' => '/files/documents/'.$filename,
            'original_filename' => 'sample.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => filesize($full) ?: 12,
            'is_published' => true,
            'sort_order' => 0,
            'published_at' => now(),
        ], $overrides));
    }
}
