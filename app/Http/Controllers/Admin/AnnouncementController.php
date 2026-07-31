<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAnnouncementRequest;
use App\Services\AnnouncementService;
use App\Services\ContactMessageService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Throwable;

class AnnouncementController extends Controller
{
    public function __construct(
        private readonly AnnouncementService $announcementService,
        private readonly ContactMessageService $contactMessageService,
    ) {}

    public function index(): View
    {
        $announcements = [];
        try {
            $announcements = $this->announcementService->getAll();
        } catch (Throwable) {
        }

        return view('admin.announcements.index', [
            'pageTitle' => 'Announcements',
            'activePage' => 'announcements',
            'announcements' => $announcements,
            'counts' => $this->messageCounts(),
        ]);
    }

    public function create(): View
    {
        return view('admin.announcements.form', [
            'pageTitle' => 'Create Announcement',
            'activePage' => 'announcements',
            'announcement' => [
                'title' => '',
                'body' => '',
                'isActive' => true,
                'publishedAt' => now()->format('Y-m-d\TH:i'),
            ],
            'errors' => [],
            'counts' => $this->messageCounts(),
        ]);
    }

    public function store(StoreAnnouncementRequest $request): RedirectResponse
    {
        $this->announcementService->create($this->parsedData($request));

        return redirect('/admin/announcements');
    }

    public function edit(string $id): View
    {
        $announcement = $this->announcementService->getById($id);
        if ($announcement === null) {
            abort(404, 'Announcement not found.');
        }

        return view('admin.announcements.form', [
            'pageTitle' => 'Edit Announcement',
            'activePage' => 'announcements',
            'announcement' => $announcement,
            'errors' => [],
            'counts' => $this->messageCounts(),
        ]);
    }

    public function update(StoreAnnouncementRequest $request, string $id): View|RedirectResponse
    {
        if ($this->announcementService->getById($id) === null) {
            abort(404, 'Announcement not found.');
        }

        $this->announcementService->update($id, $this->parsedData($request));

        return redirect('/admin/announcements');
    }

    public function destroy(string $id): RedirectResponse
    {
        $this->announcementService->delete($id);

        return redirect('/admin/announcements');
    }

    /**
     * @return array{title: string, body: string, is_active: bool, published_at: string}
     */
    private function parsedData(StoreAnnouncementRequest $request): array
    {
        $validated = $request->validated();

        return [
            'title' => $validated['title'],
            'body' => $validated['body'],
            'is_active' => $request->boolean('is_active'),
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
