<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;

use App\Services\GalleryService;

use Throwable;

class PageController extends Controller
{
    private const GALLERY_PREVIEW_FALLBACK_FOLDER = '21.02.2026-LSD';

    private const HOMEPAGE_PREVIEW_LIMIT = 6;

    private const IMAGE_EXTS = ['webp', 'jpg', 'jpeg', 'png'];

    public function __construct(
        private readonly GalleryService $galleryService,
    ) {}

    public function about(): View
    {
        $galleryPreview = [];

        try {
            $galleryPreview = $this->galleryService->getHomepageMedia(self::HOMEPAGE_PREVIEW_LIMIT);
        } catch (Throwable) {
        }

        if ($galleryPreview === []) {
            $galleryPreview = $this->getHomepageFallbackMedia();
        }

        return view('pages.about', [
            'title' => 'About',
            'description' => "Learn about Lusaka Fitness Squad — Zambia's biggest running community. Our history, mission, values, leadership, and satellites.",
            'page' => 'about',
            'bodyClass' => 'page-no-hero',
            'galleryPreview' => $galleryPreview,
        ]);
    }

    public function privacy(): View
    {
        return view('pages.privacy', [
            'title' => 'Privacy Policy',
            'description' => 'How Lusaka Fitness Squad collects, uses, and protects your personal information.',
            'page' => 'privacy',
        ]);
    }

    public function notFound(): Response
    {
        return response()->view('pages.404', [
            'title' => 'Page Not Found',
            'description' => '',
            'bodyClass' => 'page-no-hero',
        ], 404);
    }

    /**
     * @return list<array{urls: array<string, string>, albumId: string, caption: string}>
     */
    private function getHomepageFallbackMedia(): array
    {
        $folderPath = public_path('images/'.self::GALLERY_PREVIEW_FALLBACK_FOLDER);
        $baseUrl = '/images/'.self::GALLERY_PREVIEW_FALLBACK_FOLDER;

        if (! is_dir($folderPath)) {
            return [];
        }

        $files = array_filter(
            scandir($folderPath) ?: [],
            fn (string $f): bool => $f !== '.' && $f !== '..'
                && in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), self::IMAGE_EXTS, true)
        );
        sort($files);
        $files = array_slice(array_values($files), 0, self::HOMEPAGE_PREVIEW_LIMIT);

        return array_map(fn (string $f): array => [
            'urls' => ['medium' => "$baseUrl/$f", 'large' => "$baseUrl/$f", 'original' => "$baseUrl/$f"],
            'albumId' => '',
            'caption' => 'LFS — 21.02.2026 LSD',
        ], $files);
    }
}
