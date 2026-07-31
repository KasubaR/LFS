<?php

namespace App\Services;

use App\Models\Album;
use App\Models\GallerySetting;
use App\Models\Media;
use App\Support\Uuid;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GalleryService
{
    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    public function getAlbums(array $query = []): array
    {
        $search = isset($query['search']) ? trim((string) $query['search']) : '';
        $category = $query['category'] ?? '';
        $year = $query['year'] ?? '';

        $builder = Album::query()
            ->orderByRaw('ISNULL(date) ASC, date DESC, created_at DESC');

        if ($category !== '') {
            $builder->where('category', $category);
        }
        if ($year !== '') {
            $builder->whereBetween('date', ["{$year}-01-01 00:00:00", "{$year}-12-31 23:59:59"]);
        }
        if ($search !== '') {
            $like = '%'.$search.'%';
            $builder->where(function ($q) use ($like): void {
                $q->where('title', 'like', $like)
                    ->orWhere('description', 'like', $like);
            });
        }

        return $builder->get()->map(fn (Album $album) => $this->toAlbum($album))->all();
    }

    public function getAlbumById(string $id): ?array
    {
        $album = Album::query()->find($id);

        return $album ? $this->toAlbum($album) : null;
    }

    public function countFeaturedAlbums(): int
    {
        return Album::query()->where('featured', true)->count();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getAlbumsForUpload(): array
    {
        return Album::query()
            ->orderByRaw('ISNULL(date) ASC, date DESC')
            ->get()
            ->map(fn (Album $album) => $this->toAlbum($album))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createAlbum(array $data): array
    {
        $id = Uuid::v4();

        Album::query()->create([
            'id' => $id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'category' => $data['category'] ?? null,
            'date' => ! empty($data['date']) ? $data['date'] : null,
            'location' => $data['location'] ?? null,
            'event' => $data['event'] ?? null,
            'tags' => $data['tags'] ?? [],
            'cover_image' => $data['coverImage'] ?? null,
            'external_url' => ! empty($data['externalUrl']) ? $data['externalUrl'] : null,
            'media_count' => (int) ($data['mediaCount'] ?? 0),
            'featured' => (bool) ($data['featured'] ?? false),
            'homepage_slider' => (bool) ($data['homepageSlider'] ?? false),
            'event_highlight' => (bool) ($data['eventHighlight'] ?? false),
            'sort_priority' => (int) ($data['sortPriority'] ?? 0),
        ]);

        return $this->getAlbumById($id) ?? [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateAlbum(string $id, array $data): ?array
    {
        $map = [
            'title' => ['title', 'string'],
            'description' => ['description', 'string'],
            'category' => ['category', 'string'],
            'date' => ['date', 'string_null'],
            'location' => ['location', 'string'],
            'event' => ['event', 'string'],
            'tags' => ['tags', 'json'],
            'coverImage' => ['cover_image', 'string'],
            'externalUrl' => ['external_url', 'string_null'],
            'mediaCount' => ['media_count', 'int'],
            'sortPriority' => ['sort_priority', 'int'],
        ];

        $updates = [];
        foreach ($map as $camel => [$snake, $type]) {
            if (! array_key_exists($camel, $data)) {
                continue;
            }

            $value = $data[$camel];
            $updates[$snake] = match ($type) {
                'string' => (string) $value,
                'string_null' => ($value !== null && $value !== '') ? (string) $value : null,
                'int' => (int) $value,
                'json' => is_array($value) ? $value : [],
                default => $value,
            };
        }

        if ($updates !== []) {
            $updates['updated_at'] = now();
            Album::query()->whereKey($id)->update($updates);
        }

        return $this->getAlbumById($id);
    }

    public function incrementAlbumMediaCount(string $albumId, int $delta): void
    {
        Album::query()
            ->whereKey($albumId)
            ->update([
                'media_count' => DB::raw('GREATEST(0, media_count + '.(int) $delta.')'),
                'updated_at' => now(),
            ]);
    }

    public function deleteAlbum(string $id): void
    {
        Album::query()->whereKey($id)->delete();
    }

    public function countMedia(): int
    {
        return Media::query()->count();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getMediaByAlbumId(string $albumId, string $sort = 'newest'): array
    {
        $query = Media::query()->where('album_id', $albumId);

        match ($sort) {
            'oldest' => $query->orderBy('created_at'),
            'featured' => $query->orderByDesc('featured')->orderByDesc('created_at'),
            default => $query->orderByDesc('created_at'),
        };

        return $query->get()->map(fn (Media $media) => $this->toMedia($media))->all();
    }

    public function getMediaById(string $id): ?array
    {
        $media = Media::query()->find($id);

        return $media ? $this->toMedia($media) : null;
    }

    /**
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    public function findMediaByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Media::query()
            ->whereIn('id', $ids)
            ->get()
            ->map(fn (Media $media) => $this->toMedia($media))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getHomepageSliderMedia(int $limit = 10): array
    {
        return Media::query()
            ->select([
                'id', 'album_id', 'urls', 'caption', 'featured',
                'homepage_slider', 'event_highlight', 'type',
            ])
            ->where('homepage_slider', true)
            ->where('type', 'photo')
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (Media $media) => $this->toMedia($media))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getHomepageMedia(int $limit = 6): array
    {
        return Media::query()
            ->select([
                'id', 'album_id', 'urls', 'caption', 'featured',
                'homepage_slider', 'event_highlight', 'type',
            ])
            ->where('type', 'photo')
            ->orderByDesc('homepage_slider')
            ->orderByDesc('featured')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (Media $media) => $this->toMedia($media))
            ->all();
    }

    public function getGalleryBanner(): ?string
    {
        $setting = GallerySetting::query()->find(1);
        $value = $setting?->banner_image;
        $value = $value !== null ? trim((string) $value) : '';

        return $value !== '' ? $value : null;
    }

    public function setGalleryBanner(?string $bannerImage): void
    {
        $value = $bannerImage !== null ? trim($bannerImage) : null;
        $stored = ($value !== null && $value !== '') ? $value : null;

        GallerySetting::query()->updateOrCreate(
            ['id' => 1],
            ['banner_image' => $stored]
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createMedia(array $data): array
    {
        $id = Uuid::v4();

        Media::query()->create([
            'id' => $id,
            'album_id' => $data['albumId'],
            'filename' => $data['filename'] ?? null,
            'stored_name' => $data['storedName'] ?? null,
            'type' => $data['type'],
            'mimetype' => $data['mimetype'] ?? null,
            'size' => $data['size'] ?? null,
            'urls' => $data['urls'] ?? [],
            'caption' => $data['caption'] ?? '',
            'tags' => $data['tags'] ?? [],
            'featured' => (bool) ($data['featured'] ?? false),
            'homepage_slider' => (bool) ($data['homepageSlider'] ?? false),
            'event_highlight' => (bool) ($data['eventHighlight'] ?? false),
            'sort_order' => (int) ($data['sortOrder'] ?? 0),
        ]);

        return $this->getMediaById($id) ?? [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $opts
     */
    public function updateMedia(string $id, array $data, array $opts = []): ?array
    {
        $map = [
            'caption' => ['caption', 'string'],
            'featured' => ['featured', 'bool'],
            'homepageSlider' => ['homepage_slider', 'bool'],
            'eventHighlight' => ['event_highlight', 'bool'],
            'sortOrder' => ['sort_order', 'int'],
            'albumId' => ['album_id', 'string'],
        ];

        $updates = [];
        foreach ($map as $camel => [$snake, $type]) {
            if (! array_key_exists($camel, $data)) {
                continue;
            }

            $value = $data[$camel];
            $updates[$snake] = match ($type) {
                'bool' => (bool) $value,
                'int' => (int) $value,
                default => (string) $value,
            };
        }

        if ($updates !== []) {
            $updates['updated_at'] = now();
            Media::query()->whereKey($id)->update($updates);
        }

        return ! empty($opts['new']) ? $this->getMediaById($id) : null;
    }

    public function deleteMedia(string $id): void
    {
        Media::query()->whereKey($id)->delete();
    }

    public function deleteMediaByAlbumId(string $albumId): void
    {
        Media::query()->where('album_id', $albumId)->delete();
    }

    /**
     * @param  list<string>  $ids
     */
    public function deleteManyMedia(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        Media::query()->whereIn('id', $ids)->delete();
    }

    /**
     * @param  list<string>  $ids
     * @param  array<string, mixed>  $data
     */
    public function updateManyMedia(array $ids, array $data): void
    {
        if ($ids === []) {
            return;
        }

        $updates = ['updated_at' => now()];

        if (array_key_exists('featured', $data)) {
            $updates['featured'] = (bool) $data['featured'];
        }
        if (array_key_exists('homepageSlider', $data)) {
            $updates['homepage_slider'] = (bool) $data['homepageSlider'];
        }
        if (array_key_exists('eventHighlight', $data)) {
            $updates['event_highlight'] = (bool) $data['eventHighlight'];
        }
        if (array_key_exists('albumId', $data)) {
            $updates['album_id'] = $data['albumId'];
        }

        if (count($updates) === 1) {
            return;
        }

        Media::query()->whereIn('id', $ids)->update($updates);
    }

    /**
     * @return array<string, mixed>
     */
    private function toAlbum(Album $album): array
    {
        return [
            '_id' => $album->id,
            'id' => $album->id,
            'title' => $album->title,
            'description' => $album->description,
            'category' => $album->category,
            'date' => $this->formatDateTime($album->date),
            'location' => $album->location,
            'event' => $album->event,
            'tags' => $album->tags ?? [],
            'coverImage' => $album->cover_image,
            'externalUrl' => $album->external_url ?? '',
            'mediaCount' => (int) ($album->media_count ?? 0),
            'featured' => (bool) $album->featured,
            'homepageSlider' => (bool) $album->homepage_slider,
            'eventHighlight' => (bool) $album->event_highlight,
            'sortPriority' => (int) ($album->sort_priority ?? 0),
            'createdAt' => $this->formatDateTime($album->created_at),
            'updatedAt' => $this->formatDateTime($album->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toMedia(Media $media): array
    {
        return [
            '_id' => $media->id,
            'id' => $media->id,
            'albumId' => $media->album_id,
            'filename' => $media->filename,
            'storedName' => $media->stored_name,
            'type' => $media->type ?? 'photo',
            'mimetype' => $media->mimetype,
            'size' => $media->size,
            'urls' => $media->urls ?? [],
            'caption' => $media->caption ?? '',
            'tags' => $media->tags ?? [],
            'featured' => (bool) $media->featured,
            'homepageSlider' => (bool) $media->homepage_slider,
            'eventHighlight' => (bool) $media->event_highlight,
            'sortOrder' => (int) ($media->sort_order ?? 0),
            'createdAt' => $this->formatDateTime($media->created_at),
            'updatedAt' => $this->formatDateTime($media->updated_at),
        ];
    }

    private function formatDateTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }
}
