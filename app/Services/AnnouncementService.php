<?php

namespace App\Services;

use App\Models\Announcement;
use App\Support\Uuid;

class AnnouncementService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function getAll(): array
    {
        return Announcement::query()
            ->orderByDesc('published_at')
            ->get()
            ->map(fn (Announcement $announcement) => $this->toAnnouncement($announcement))
            ->all();
    }

    /**
     * Active, already-published announcements for the member dashboard.
     *
     * @return list<array<string, mixed>>
     */
    public function getActiveForMembers(int $limit = 5): array
    {
        return Announcement::query()
            ->where('is_active', true)
            ->where('published_at', '<=', now())
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get()
            ->map(fn (Announcement $announcement) => $this->toAnnouncement($announcement))
            ->all();
    }

    public function getById(string $id): ?array
    {
        $announcement = Announcement::query()->find($id);

        return $announcement ? $this->toAnnouncement($announcement) : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): string
    {
        $id = Uuid::v4();

        Announcement::query()->create([
            'id' => $id,
            'title' => trim($data['title'] ?? ''),
            'body' => trim($data['body'] ?? ''),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'published_at' => $data['published_at'] ?? now(),
        ]);

        return $id;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(string $id, array $data): bool
    {
        return Announcement::query()->whereKey($id)->update([
            'title' => trim($data['title'] ?? ''),
            'body' => trim($data['body'] ?? ''),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'published_at' => $data['published_at'] ?? now(),
        ]) > 0;
    }

    public function delete(string $id): bool
    {
        return Announcement::query()->whereKey($id)->delete() > 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function toAnnouncement(Announcement $announcement): array
    {
        return [
            'id' => $announcement->id,
            'title' => $announcement->title,
            'body' => $announcement->body,
            'isActive' => (bool) $announcement->is_active,
            'publishedAt' => (string) $announcement->published_at,
            'createdAt' => (string) $announcement->created_at,
        ];
    }
}
