<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DocumentCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDocumentRequest;
use App\Services\ContactMessageService;
use App\Services\DocumentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Throwable;

class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentService $documentService,
        private readonly ContactMessageService $contactMessageService,
    ) {}

    public function index(): View
    {
        $documents = [];
        try {
            $documents = $this->documentService->getAll();
        } catch (Throwable) {
        }

        return view('admin.documents.index', [
            'pageTitle' => 'Documents',
            'activePage' => 'documents',
            'documents' => $documents,
            'counts' => $this->messageCounts(),
        ]);
    }

    public function create(): View
    {
        return view('admin.documents.form', [
            'pageTitle' => 'Upload Document',
            'activePage' => 'documents',
            'document' => [
                'title' => '',
                'category' => DocumentCategory::Forms,
                'description' => '',
                'isPublished' => true,
                'sortOrder' => 0,
                'publishedAt' => now()->format('Y-m-d\TH:i'),
            ],
            'categories' => DocumentCategory::options(),
            'errors' => [],
            'counts' => $this->messageCounts(),
        ]);
    }

    public function store(StoreDocumentRequest $request): RedirectResponse
    {
        $file = $request->file('file');
        if ($file === null || ! $file->isValid()) {
            return back()->withErrors(['file' => 'Please upload a valid document file.'])->withInput();
        }

        $this->documentService->create($this->parsedData($request), $file);

        return redirect('/admin/documents');
    }

    public function edit(string $id): View
    {
        $document = $this->documentService->getById($id);
        if ($document === null) {
            abort(404, 'Document not found.');
        }

        return view('admin.documents.form', [
            'pageTitle' => 'Edit Document',
            'activePage' => 'documents',
            'document' => $document,
            'categories' => DocumentCategory::options(),
            'errors' => [],
            'counts' => $this->messageCounts(),
        ]);
    }

    public function update(StoreDocumentRequest $request, string $id): RedirectResponse
    {
        if ($this->documentService->getById($id) === null) {
            abort(404, 'Document not found.');
        }

        $file = $request->file('file');
        if ($file !== null && ! $file->isValid()) {
            return back()->withErrors(['file' => 'Please upload a valid document file.'])->withInput();
        }

        $this->documentService->update($id, $this->parsedData($request), $file);

        return redirect('/admin/documents');
    }

    public function destroy(string $id): RedirectResponse
    {
        $this->documentService->delete($id);

        return redirect('/admin/documents');
    }

    /**
     * @return array{title: string, category: string, description: ?string, is_published: bool, sort_order: int, published_at: mixed}
     */
    private function parsedData(StoreDocumentRequest $request): array
    {
        $validated = $request->validated();

        return [
            'title' => $validated['title'],
            'category' => $validated['category'],
            'description' => $validated['description'] ?? null,
            'is_published' => $request->boolean('is_published'),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'published_at' => $validated['published_at'] ?? now(),
        ];
    }

    /**
     * @return array{newMessages: int, pendingMembers: int, pendingOrders: int, pendingGallery: int}
     */
    private function messageCounts(): array
    {
        $newMessages = 0;
        try {
            $newMessages = ($this->contactMessageService->countByStatus())['New'] ?? 0;
        } catch (Throwable) {
        }

        return [
            'newMessages' => $newMessages,
            'pendingMembers' => 0,
            'pendingOrders' => 0,
            'pendingGallery' => 0,
        ];
    }
}
