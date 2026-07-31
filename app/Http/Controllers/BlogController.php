<?php

namespace App\Http\Controllers;

use App\Enums\BlogCategory;
use App\Services\BlogPostService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class BlogController extends Controller
{
    public function __construct(
        private readonly BlogPostService $blogPostService,
    ) {}

    public function index(): View
    {
        $activeCategory = trim((string) request('category', ''));
        $searchQuery = trim((string) request('q', ''));
        $currentPage = max(1, (int) request('page', 1));
        $perPage = 9;
        $offset = ($currentPage - 1) * $perPage;

        $opts = [
            'status' => 'published',
            'limit' => $perPage,
            'offset' => $offset,
        ];
        if ($activeCategory !== '') {
            $opts['category'] = $activeCategory;
        }
        if ($searchQuery !== '') {
            $opts['search'] = $searchQuery;
        }

        try {
            ['posts' => $rawPosts, 'total' => $total] = $this->blogPostService->getPosts($opts);
        } catch (Throwable $e) {
            Log::error('[LFS] News listing error: '.$e->getMessage());
            $rawPosts = [];
            $total = 0;
        }

        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;
        $posts = array_map(fn (array $p) => $this->mapPostForView($p), $rawPosts);

        $featured = ($currentPage === 1 && $activeCategory === '' && $searchQuery === '' && isset($posts[0]))
            ? $posts[0]
            : null;

        return view('pages.news', [
            'title' => 'News & Updates — LFS',
            'description' => 'Stories from the track, the trail, and the community. Club news, race reports, training tips, and announcements from Lusaka Fitness Squad.',
            'page' => 'news',
            'posts' => $posts,
            'featured' => $featured,
            'activeCategory' => $activeCategory,
            'searchQuery' => $searchQuery,
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
            'categories' => BlogCategory::ALL,
            'extraStyles' => '<link rel="stylesheet" href="/css/blog.css">',
            'extraScripts' => '<script src="/js/blog.js" defer></script>',
        ]);
    }

    public function show(string $slug): View|Response
    {
        try {
            $post = $this->blogPostService->getPostBySlug($slug);
        } catch (Throwable $e) {
            Log::error('[LFS] News post error: '.$e->getMessage());
            $post = null;
        }

        if (! $post || ($post['status'] ?? '') !== 'published') {
            return response()->view('pages.404', [
                'title' => 'Page Not Found',
                'description' => '',
                'page' => 'news',
                'bodyClass' => 'page-no-hero',
            ], 404);
        }

        $this->blogPostService->incrementViews($post['id']);

        $postForView = $this->mapPostForView($post);
        $relatedPosts = [];
        $prevPost = null;
        $nextPost = null;

        try {
            if (! empty($post['category'])) {
                ['posts' => $relatedRaw] = $this->blogPostService->getPosts([
                    'status' => 'published',
                    'category' => $post['category'],
                    'limit' => 4,
                ]);
                $relatedPosts = array_slice(
                    array_map(
                        fn (array $p) => $this->mapPostForView($p),
                        array_filter($relatedRaw, fn (array $p): bool => ($p['id'] ?? '') !== $post['id'])
                    ),
                    0,
                    3
                );
            }

            ['posts' => $allByDate] = $this->blogPostService->getPosts([
                'status' => 'published',
                'limit' => 100,
                'offset' => 0,
            ]);
            $ids = array_column($allByDate, 'id');
            $pos = array_search($post['id'], $ids, true);
            if ($pos !== false && $pos > 0) {
                $prevPost = $this->mapPostForView($allByDate[$pos - 1]);
            }
            if ($pos !== false && $pos < count($allByDate) - 1) {
                $nextPost = $this->mapPostForView($allByDate[$pos + 1]);
            }
        } catch (Throwable $e) {
            Log::error('[LFS] News related/prev-next error: '.$e->getMessage());
        }

        return view('pages.news-post', [
            'title' => $post['title'] ?? 'News',
            'description' => strip_tags($post['excerpt'] ?? $post['title'] ?? ''),
            'page' => 'news',
            'post' => $postForView,
            'relatedPosts' => $relatedPosts,
            'prevPost' => $prevPost,
            'nextPost' => $nextPost,
            'extraStyles' => '<link rel="stylesheet" href="/css/blog.css">',
            'extraScripts' => '<script src="/js/blog.js" defer></script>',
        ]);
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function mapPostForView(array $p): array
    {
        return $p + [
            'image' => $p['featuredImage'] ?? '',
            'date' => $p['publishDate'] ?? null,
            'published_at' => $p['publishDate'] ?? null,
        ];
    }
}
