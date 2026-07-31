<?php

namespace App\Http\Controllers;

use App\Services\GalleryService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class GalleryController extends Controller
{
    private const FALLBACK_FOLDER = '21.02.2026-LSD';

    private const IMAGE_EXTS = ['webp', 'jpg', 'jpeg', 'png'];

    public function __construct(
        private readonly GalleryService $galleryService,
    ) {}

    public function index(): View
    {
        $albums = [];
        $galleryError = null;
        $fallbackMedia = [];
        $galleryBanner = null;

        try {
            $albums = $this->galleryService->getAlbums([]);
            $galleryBanner = $this->galleryService->getGalleryBanner();
        } catch (Throwable $e) {
            $galleryError = 'Gallery is temporarily unavailable. Please try again later.';
            Log::error('[LFS Gallery] Error loading albums: '.$e->getMessage());
        }

        if ($albums === []) {
            $fallbackMedia = $this->getFallbackMedia();
        }

        return view('pages.gallery', [
            'title' => 'Gallery',
            'description' => 'Photos and videos from LFS runs, races and community events.',
            'albums' => $albums,
            'galleryError' => $galleryError,
            'fallbackMedia' => $fallbackMedia,
            'galleryBanner' => $galleryBanner,
            'extraStyles' => '<link rel="stylesheet" href="/css/gallery.css">',
        ]);
    }

    public function show(string $id): View|Response
    {
        try {
            $album = $this->galleryService->getAlbumById($id);
        } catch (Throwable $e) {
            Log::error('[LFS Gallery] Error loading album '.$id.': '.$e->getMessage());
            $album = null;
        }

        if (! $album) {
            return response()->view('pages.404', [
                'title' => 'Album not found',
                'description' => 'This album may have been removed or the link is invalid.',
                'bodyClass' => 'page-no-hero',
            ], 404);
        }

        try {
            $media = $this->galleryService->getMediaByAlbumId($id, 'newest');
        } catch (Throwable $e) {
            Log::error('[LFS Gallery] Error loading album media '.$id.': '.$e->getMessage());
            $media = [];
        }

        return view('pages.gallery-album', [
            'title' => $album['title'],
            'description' => ! empty($album['description'])
                ? $album['description']
                : 'Photos and videos from '.$album['title'].'.',
            'album' => $album,
            'media' => $media,
            'extraStyles' => '<link rel="stylesheet" href="/css/events.css">',
            'extraScripts' => $this->buildLightboxScripts($media),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $media
     */
    private function buildLightboxScripts(array $media): string
    {
        $lbData = array_map(fn (array $m): array => [
            'type' => $m['type'] ?? 'image',
            'caption' => $m['caption'] ?? '',
            'large' => $m['urls']['large'] ?? $m['urls']['medium'] ?? $m['urls']['original'] ?? '',
            'video' => $m['urls']['original'] ?? '',
        ], $media);

        return '<script>var LB_MEDIA = '
            .json_encode($lbData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE)
            .';</script><script src="'.e(asset('js/gallery-lightbox.js')).'"></script>';
    }

    /**
     * @return list<array{src: string, alt: string}>
     */
    private function getFallbackMedia(): array
    {
        $folderPath = public_path('images/'.self::FALLBACK_FOLDER);
        $baseUrl = '/images/'.self::FALLBACK_FOLDER;

        if (! is_dir($folderPath)) {
            return [];
        }

        $files = array_filter(
            scandir($folderPath) ?: [],
            fn (string $f): bool => $f !== '.' && $f !== '..'
                && in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), self::IMAGE_EXTS, true)
        );
        sort($files);

        return array_values(array_map(
            fn (string $f): array => [
                'src' => $baseUrl.'/'.$f,
                'alt' => 'LFS — '.self::FALLBACK_FOLDER,
            ],
            $files
        ));
    }
}
