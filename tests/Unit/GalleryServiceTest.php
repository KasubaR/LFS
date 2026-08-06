<?php

namespace Tests\Unit;

use App\Models\Album;
use App\Services\GalleryService;
use App\Support\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GalleryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_increment_album_media_count_adds_delta(): void
    {
        $album = Album::query()->create([
            'id' => Uuid::v4(),
            'title' => 'Test Album',
            'media_count' => 2,
        ]);

        (new GalleryService)->incrementAlbumMediaCount($album->id, 3);

        $this->assertSame(5, $album->fresh()->media_count);
    }

    public function test_increment_album_media_count_clamps_at_zero(): void
    {
        $album = Album::query()->create([
            'id' => Uuid::v4(),
            'title' => 'Test Album',
            'media_count' => 2,
        ]);

        (new GalleryService)->incrementAlbumMediaCount($album->id, -10);

        $this->assertSame(0, $album->fresh()->media_count);
    }

    public function test_get_albums_orders_by_date_with_null_dates_last(): void
    {
        $withDate = Album::query()->create([
            'id' => Uuid::v4(),
            'title' => 'Has Date',
            'date' => '2026-01-01 00:00:00',
        ]);

        $withoutDate = Album::query()->create([
            'id' => Uuid::v4(),
            'title' => 'No Date',
            'date' => null,
        ]);

        $albums = (new GalleryService)->getAlbums();
        $ids = array_column($albums, 'id');

        $this->assertSame(
            [$withDate->id, $withoutDate->id],
            array_values(array_intersect($ids, [$withDate->id, $withoutDate->id]))
        );
    }
}
