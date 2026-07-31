<?php

namespace App\Services;

use App\Enums\DocumentCategory;
use App\Models\Document;
use App\Support\Uuid;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

class DocumentService
{
    private const UPLOAD_PREFIX = '/files/documents/';

    private const UPLOAD_DIR = 'files/documents';

    /**
     * @return list<array<string, mixed>>
     */
    public function getAll(): array
    {
        return Document::query()
            ->orderBy('category')
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get()
            ->map(fn (Document $document) => $this->toDocument($document))
            ->all();
    }

    /**
     * Published documents for paid members, grouped by category.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function getPublishedGroupedForMembers(): array
    {
        $documents = Document::query()
            ->where('is_published', true)
            ->where(function ($query): void {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->orderBy('category')
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get()
            ->map(fn (Document $document) => $this->toDocument($document))
            ->all();

        $grouped = [];
        foreach (DocumentCategory::ALL as $category) {
            $grouped[$category] = [];
        }

        foreach ($documents as $document) {
            $category = $document['category'];
            if (! isset($grouped[$category])) {
                $grouped[$category] = [];
            }
            $grouped[$category][] = $document;
        }

        return array_filter($grouped, fn (array $items) => $items !== []);
    }

    public function getById(string $id): ?array
    {
        $document = Document::query()->find($id);

        return $document ? $this->toDocument($document) : null;
    }

    public function findPublishedForDownload(string $id): ?Document
    {
        return Document::query()
            ->whereKey($id)
            ->where('is_published', true)
            ->where(function ($query): void {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, UploadedFile $file): string
    {
        $id = Uuid::v4();
        $stored = $this->storeUpload($file);

        Document::query()->create([
            'id' => $id,
            'title' => trim((string) ($data['title'] ?? '')),
            'category' => (string) ($data['category'] ?? DocumentCategory::Forms),
            'description' => $this->nullableTrim($data['description'] ?? null),
            'file_path' => $stored['file_path'],
            'original_filename' => $stored['original_filename'],
            'mime_type' => $stored['mime_type'],
            'file_size' => $stored['file_size'],
            'is_published' => (bool) ($data['is_published'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'published_at' => $data['published_at'] ?? now(),
        ]);

        return $id;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(string $id, array $data, ?UploadedFile $file = null): bool
    {
        $document = Document::query()->find($id);
        if ($document === null) {
            return false;
        }

        $updates = [
            'title' => trim((string) ($data['title'] ?? '')),
            'category' => (string) ($data['category'] ?? $document->category),
            'description' => $this->nullableTrim($data['description'] ?? null),
            'is_published' => (bool) ($data['is_published'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'published_at' => $data['published_at'] ?? $document->published_at ?? now(),
        ];

        if ($file !== null) {
            $stored = $this->storeUpload($file);
            $this->deleteLocalFile($document->file_path);
            $updates['file_path'] = $stored['file_path'];
            $updates['original_filename'] = $stored['original_filename'];
            $updates['mime_type'] = $stored['mime_type'];
            $updates['file_size'] = $stored['file_size'];
        }

        return $document->forceFill($updates)->save();
    }

    public function delete(string $id): bool
    {
        $document = Document::query()->find($id);
        if ($document === null) {
            return false;
        }

        $this->deleteLocalFile($document->file_path);

        return (bool) $document->delete();
    }

    public function absolutePath(Document $document): ?string
    {
        if (! str_starts_with((string) $document->file_path, self::UPLOAD_PREFIX)) {
            return null;
        }

        $full = public_path(ltrim((string) $document->file_path, '/'));

        return is_file($full) ? $full : null;
    }

    /**
     * @return array{file_path: string, original_filename: string, mime_type: string, file_size: int}
     */
    private function storeUpload(UploadedFile $file): array
    {
        $dir = public_path(self::UPLOAD_DIR);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        $originalName = $file->getClientOriginalName() ?: ('document'.($extension !== '' ? '.'.$extension : ''));
        $clientMime = (string) ($file->getClientMimeType() ?: 'application/octet-stream');
        $filename = 'doc_'.uniqid('', true).($extension !== '' ? '.'.$extension : '');
        $file->move($dir, $filename);

        $fullPath = $dir.DIRECTORY_SEPARATOR.$filename;

        return [
            'file_path' => self::UPLOAD_PREFIX.$filename,
            'original_filename' => $originalName,
            'mime_type' => (string) (File::mimeType($fullPath) ?: $clientMime),
            'file_size' => (int) (is_file($fullPath) ? filesize($fullPath) : 0),
        ];
    }

    private function deleteLocalFile(?string $path): void
    {
        if (! $path || ! str_starts_with($path, self::UPLOAD_PREFIX)) {
            return;
        }

        $full = public_path(ltrim($path, '/'));
        if (is_file($full)) {
            @unlink($full);
        }
    }

    private function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array<string, mixed>
     */
    private function toDocument(Document $document): array
    {
        return [
            'id' => $document->id,
            'title' => $document->title,
            'category' => $document->category,
            'categoryLabel' => DocumentCategory::label((string) $document->category),
            'description' => $document->description,
            'filePath' => $document->file_path,
            'originalFilename' => $document->original_filename,
            'mimeType' => $document->mime_type,
            'fileSize' => (int) $document->file_size,
            'isPublished' => (bool) $document->is_published,
            'sortOrder' => (int) $document->sort_order,
            'publishedAt' => $document->published_at ? (string) $document->published_at : null,
            'createdAt' => (string) $document->created_at,
        ];
    }
}
