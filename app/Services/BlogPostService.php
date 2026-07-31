<?php

namespace App\Services;

use App\Models\BlogPost;
use App\Support\HtmlPurifier;
use App\Support\Uuid;
use Illuminate\Support\Facades\Cache;

class BlogPostService
{
    private const CACHE_VERSION_KEY = 'lfs_blog_list_version';

    /**
     * @param  array<string, mixed>  $opts
     * @return array{posts: list<array<string, mixed>>, total: int}
     */
    public function getPosts(array $opts = []): array
    {
        $ttl = (int) env('BLOG_LIST_CACHE_TTL', 0);
        $cacheable = $ttl > 0
            && empty($opts['status'])
            && empty($opts['category'])
            && ! isset($opts['featured'])
            && empty($opts['search']);

        if ($cacheable) {
            $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
            $limit = (int) ($opts['limit'] ?? 50);
            $offset = (int) ($opts['offset'] ?? 0);
            $cacheKey = "lfs_blog_list_v{$version}_{$limit}_{$offset}";

            return Cache::remember($cacheKey, $ttl, fn () => $this->fetchPosts($opts));
        }

        return $this->fetchPosts($opts);
    }

    public function bustListCache(): void
    {
        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        Cache::forever(self::CACHE_VERSION_KEY, $version + 1);
    }

    public function getPostById(string $id): ?array
    {
        $post = BlogPost::query()->find($id);

        return $post ? $this->toPost($post) : null;
    }

    public function getPostBySlug(string $slug): ?array
    {
        $post = BlogPost::query()->where('slug', $slug)->first();

        return $post ? $this->toPost($post) : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createPost(array $data): array
    {
        $id = Uuid::v4();
        $slug = trim($data['slug'] ?? '');
        if ($slug === '') {
            $slug = $this->slugify($data['title'] ?? 'post');
        }
        $slug = $this->ensureUniqueSlug($slug, null);

        BlogPost::query()->create([
            'id' => $id,
            'title' => $data['title'] ?? '',
            'slug' => $slug,
            'excerpt' => $data['excerpt'] ?? null,
            'content' => HtmlPurifier::clean($data['content'] ?? null),
            'featured_image' => $data['featuredImage'] ?? null,
            'author' => $data['author'] ?? 'LFS Admin',
            'category' => $data['category'] ?? '',
            'tags' => $data['tags'] ?? [],
            'status' => $data['status'] ?? 'draft',
            'featured' => (bool) ($data['featured'] ?? false),
            'views' => 0,
            'publish_date' => $data['publishDate'] ?? null,
        ]);

        $this->bustListCache();

        return $this->getPostById($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePost(string $id, array $data): bool
    {
        $existing = $this->getPostById($id);
        if (! $existing) {
            return false;
        }

        if (array_key_exists('slug', $data)) {
            $slug = trim((string) ($data['slug'] ?? ''));
            if ($slug === '') {
                $slug = $this->slugify($data['title'] ?? $existing['title']);
            }
            $data['slug'] = $this->ensureUniqueSlug($slug, $id);
        }

        $map = [
            'title' => 'title',
            'slug' => 'slug',
            'excerpt' => 'excerpt',
            'content' => 'content',
            'featuredImage' => 'featured_image',
            'author' => 'author',
            'category' => 'category',
            'tags' => 'tags',
            'status' => 'status',
            'featured' => 'featured',
            'publishDate' => 'publish_date',
        ];

        $updates = [];
        foreach ($map as $camel => $column) {
            if (! array_key_exists($camel, $data)) {
                continue;
            }

            $value = $data[$camel];
            if ($camel === 'content') {
                $value = HtmlPurifier::clean($value);
            }
            if ($camel === 'featured') {
                $value = (bool) $value;
            }

            $updates[$column] = $value;
        }

        if ($updates === []) {
            return true;
        }

        BlogPost::query()->whereKey($id)->update($updates);
        $this->bustListCache();

        return true;
    }

    public function deletePost(string $id): bool
    {
        $deleted = BlogPost::query()->whereKey($id)->delete() > 0;

        if ($deleted) {
            $this->bustListCache();
        }

        return $deleted;
    }

    public function incrementViews(string $id): void
    {
        BlogPost::query()->whereKey($id)->increment('views');
    }

    /**
     * @param  array<string, mixed>  $opts
     * @return array{posts: list<array<string, mixed>>, total: int}
     */
    private function fetchPosts(array $opts): array
    {
        $query = BlogPost::query()->orderByDesc('created_at');

        if (! empty($opts['status'])) {
            $query->where('status', $opts['status']);
        }
        if (! empty($opts['category'])) {
            $query->where('category', $opts['category']);
        }
        if (isset($opts['featured'])) {
            $query->where('featured', (bool) $opts['featured']);
        }
        if (! empty($opts['search'])) {
            $search = '%'.$opts['search'].'%';
            $query->where(function ($builder) use ($search): void {
                $builder->where('title', 'like', $search)
                    ->orWhere('excerpt', 'like', $search);
            });
        }

        $total = (clone $query)->count();
        $limit = max(1, (int) ($opts['limit'] ?? 50));
        $offset = max(0, (int) ($opts['offset'] ?? 0));

        $posts = $query
            ->select([
                'id', 'title', 'slug', 'excerpt', 'author', 'category', 'tags',
                'status', 'featured', 'views', 'publish_date', 'created_at', 'updated_at',
            ])
            ->limit($limit)
            ->offset($offset)
            ->get()
            ->map(fn (BlogPost $post) => $this->toPost($post))
            ->all();

        return ['posts' => $posts, 'total' => $total];
    }

    /**
     * @return array<string, mixed>
     */
    private function toPost(BlogPost $post): array
    {
        return [
            'id' => $post->id,
            'title' => $post->title,
            'slug' => $post->slug,
            'excerpt' => $post->excerpt ?? '',
            'content' => $post->content ?? '',
            'featuredImage' => $post->featured_image ?? '',
            'author' => $post->author ?? 'LFS Admin',
            'category' => $post->category ?? '',
            'tags' => $post->tags ?? [],
            'status' => $post->status ?? 'draft',
            'featured' => (bool) $post->featured,
            'views' => (int) $post->views,
            'publishDate' => $this->formatDateTime($post->publish_date),
            'createdAt' => $this->formatDateTime($post->created_at),
            'updatedAt' => $this->formatDateTime($post->updated_at),
        ];
    }

    private function slugify(string $text): string
    {
        $slug = strtolower($text);
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug) ?? '';
        $slug = preg_replace('/[\s-]+/', '-', trim($slug)) ?? '';

        return substr($slug, 0, 200) ?: 'post';
    }

    private function ensureUniqueSlug(string $slug, ?string $excludeId): string
    {
        $base = $slug;
        $suffix = 1;

        while (true) {
            $query = BlogPost::query()->where('slug', $slug);
            if ($excludeId) {
                $query->where('id', '!=', $excludeId);
            }

            if (! $query->exists()) {
                return $slug;
            }

            $slug = $base.'-'.$suffix++;
        }
    }

    private function formatDateTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return (string) $value;
    }
}
