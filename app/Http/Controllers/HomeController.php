<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

use App\Enums\BlogCategory;
use App\Services\BlogPostService;
use App\Services\EventService;
use App\Services\GalleryService;
use App\Services\ProductService;
use DateTime;
use Illuminate\Support\Facades\Log;

use Throwable;

class HomeController extends Controller
{
    private const GALLERY_PREVIEW_FALLBACK_FOLDER = '21.02.2026-LSD';

    private const HOMEPAGE_PREVIEW_LIMIT = 6;

    private const IMAGE_EXTS = ['webp', 'jpg', 'jpeg', 'png'];

    public function __construct(
        private readonly GalleryService $galleryService,
        private readonly EventService $eventService,
        private readonly ProductService $productService,
        private readonly BlogPostService $blogPostService,
    ) {}

    public function index(): View
    {
        $galleryPreview = [];
        $events = [];
        $homeProducts = [];
        $heroSlides = [];
        $heroFeaturedEvents = [];
        $posts = [];

        try {
            $galleryPreview = $this->galleryService->getHomepageMedia(self::HOMEPAGE_PREVIEW_LIMIT);
        } catch (Throwable) {
        }

        if ($galleryPreview === []) {
            $galleryPreview = $this->getHomepageFallbackMedia();
        }

        try {
            $heroSlides = $this->galleryService->getHomepageSliderMedia(8);
        } catch (Throwable) {
        }

        try {
            foreach ($this->eventService->getHomeHeroFeaturedEvents(20) as $fe) {
                if (empty($fe['bannerImage'])) {
                    continue;
                }

                $dateLine = '—';
                if (! empty($fe['eventDate'])) {
                    try {
                        $dateLine = (new DateTime($fe['eventDate']))->format('D, j F Y');
                    } catch (Throwable) {
                    }
                }

                $slug = trim((string) ($fe['slug'] ?? ''));
                $heroFeaturedEvents[] = [
                    'title' => (string) ($fe['title'] ?? ''),
                    'dateLine' => $dateLine,
                    'link' => $slug !== '' ? '/events/'.rawurlencode($slug) : '',
                    'bannerImage' => (string) $fe['bannerImage'],
                ];
            }
        } catch (Throwable) {
        }

        try {
            $events = array_map(
                fn (array $e) => $this->mapEventForHome($e),
                $this->eventService->getUpcomingEvents(5)
            );
        } catch (Throwable) {
        }

        try {
            ['products' => $rawProducts] = $this->productService->getProducts(['limit' => 8], ['admin' => false]);
            if ($rawProducts !== []) {
                $homeProducts = array_map(fn (array $prod) => $this->mapProductForHome($prod), $rawProducts);
            }
        } catch (Throwable) {
        }

        try {
            ['posts' => $rawPosts] = $this->blogPostService->getPosts(['status' => 'published', 'limit' => 3]);
            if ($rawPosts !== []) {
                $posts = array_map(fn (array $p) => $this->mapPostForHome($p), $rawPosts);
            }
        } catch (Throwable $e) {
            Log::error('[LFS] Home news posts error: '.$e->getMessage());
        }

        $heroPreload = $this->buildHeroPreload($heroFeaturedEvents, $heroSlides);

        return view('pages.home', [
            'title' => 'Home',
            'description' => "Zambia's biggest running community. Train. Run. Compete. Together. Join LFS today.",
            'page' => 'home',
            'galleryPreview' => $galleryPreview,
            'heroSlides' => $heroSlides,
            'heroFeaturedEvents' => $heroFeaturedEvents,
            'events' => $events,
            'products' => $homeProducts,
            'posts' => $posts,
            'heroImage' => '/images/home/home-hero.jpg',
            'extraStyles' => $heroPreload.'<link rel="stylesheet" href="/css/home.css">',
            'extraScripts' => '<script src="/js/home.js" defer></script>',
        ]);
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

    /**
     * @param  array<string, mixed>  $e
     * @return array<string, mixed>
     */
    private function mapEventForHome(array $e): array
    {
        $date = ! empty($e['eventDate']) ? new DateTime($e['eventDate']) : null;

        return [
            'title' => $e['title'],
            'date' => $date ? $date->format('D, j F Y') : 'TBA',
            'location' => $e['location'] ?: 'TBA',
            'distance' => $e['distance'] ?: '—',
            'tag' => $e['category'] ?: 'Event',
            'tagColor' => $this->eventTagColor($e['category'] ?? ''),
            'link' => '/events/'.($e['slug'] ?: $e['id']),
        ];
    }

    private function eventTagColor(string $category): string
    {
        return match ($category) {
            'LSD' => 'green',
            'Road Race' => 'orange',
            'Training', 'Training Camp' => 'red',
            'Social' => 'gold',
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $prod
     * @return array<string, mixed>
     */
    private function mapProductForHome(array $prod): array
    {
        $image = $prod['thumbnail'] ?? '';
        if ((! $image || $image === '/images/products/placeholder.webp') && ! empty($prod['images'])) {
            $first = $prod['images'][0];
            $image = is_string($first) ? $first : ($first['url'] ?? $first['src'] ?? $image);
        }
        if (! $image) {
            $image = 'https://images.unsplash.com/photo-1515886657613-9f3515b0c78f?q=80&w=2124&auto=format&fit=crop';
        }

        return [
            'name' => $prod['name'],
            'sub' => $prod['shortDescription'] ?: ($prod['description'] ?? ''),
            'category' => $prod['category'] ?? '',
            'price' => (float) ($prod['price'] ?? 0),
            'comparePrice' => isset($prod['comparePrice']) && $prod['comparePrice'] !== null && $prod['comparePrice'] !== ''
                ? (float) $prod['comparePrice'] : null,
            'slug' => $prod['slug'] ?? null,
            'badge' => ! empty($prod['featured']) ? 'Featured' : null,
            'badgeColor' => ! empty($prod['featured']) ? 'gold' : null,
            'image' => $image,
            'thumbnail' => $image,
        ];
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function mapPostForHome(array $p): array
    {
        $date = $p['publishDate'] ?? null;
        $dateStr = $date && (($t = strtotime((string) $date)) !== false) ? date('j M Y', $t) : '—';

        return [
            'title' => $p['title'] ?? '',
            'excerpt' => $p['excerpt'] ?? '',
            'date' => $dateStr,
            'category' => $p['category'] ?? 'News',
            'image' => $p['featuredImage'] ?? '',
            'link' => '/news/'.($p['slug'] ?? ''),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $heroFeaturedEvents
     * @param  list<mixed>  $heroSlides
     */
    private function buildHeroPreload(array $heroFeaturedEvents, array $heroSlides): string
    {
        $heroPreloadHref = null;

        if (! empty($heroFeaturedEvents[0]['bannerImage'])) {
            $hb = $heroFeaturedEvents[0]['bannerImage'];
            $heroPreloadHref = preg_match('#^https?://#i', $hb) === 1 ? $hb : asset(ltrim($hb, '/'));
        } elseif ($heroSlides === []) {
            $heroPreloadHref = asset('images/home/home-hero.jpg');
        } else {
            $h0 = $heroSlides[0];
            $h0u = is_array($h0) ? ($h0['urls']['large'] ?? $h0['urls']['original'] ?? $h0['urls']['medium'] ?? '') : '';
            if (is_string($h0u) && $h0u !== '') {
                $heroPreloadHref = preg_match('#^https?://#i', $h0u) === 1 ? $h0u : asset(ltrim($h0u, '/'));
            }
        }

        if ($heroPreloadHref === null) {
            return '';
        }

        return '<link rel="preload" as="image" href="'.e($heroPreloadHref).'">';
    }
}
