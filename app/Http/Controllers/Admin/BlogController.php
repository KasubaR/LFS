<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Response;

use App\Enums\BlogCategory;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use App\Services\BlogPostService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use Throwable;

class BlogController extends Controller
{
    /** @var list<string> */
    private const STATUSES = ['draft', 'published'];

    public function __construct(
        private readonly BlogPostService $blogPostService,
    ) {}

    public function index(Request $request): View
    {
        $status = $request->query('status', '');
        $category = $request->query('category', '');
        $search = $request->query('search', '');

        $opts = ['limit' => 100];
        if ($status) {
            $opts['status'] = $status;
        }
        if ($category) {
            $opts['category'] = $category;
        }
        if ($search) {
            $opts['search'] = $search;
        }

        $posts = [];
        $postsError = null;
        $total = 0;
        try {
            ['posts' => $posts, 'total' => $total] = $this->blogPostService->getPosts($opts);
        } catch (Throwable $e) {
            $postsError = $e->getMessage() ?: 'Could not load posts. Check database connection.';
            Log::error('[LFS Admin] BlogController::index — '.$e->getMessage());
        }

        return view('admin.blog.list', [
            'pageTitle' => 'Blog Posts',
            'activePage' => 'blog',
            'posts' => $posts,
            'total' => $total,
            'postsError' => $postsError,
            'categories' => BlogCategory::ALL,
            'statuses' => self::STATUSES,
            'filterStatus' => $status,
            'filterCategory' => $category,
            'filterSearch' => $search,
            'breadcrumbs' => [
                ['label' => 'Admin', 'url' => '/admin'],
                ['label' => 'Blog'],
            ],
        ]);
    }

    public function create(): View
    {
        return view('admin.blog.create', array_merge([
            'pageTitle' => 'New Post',
            'activePage' => 'blog',
            'post' => null,
            'categories' => BlogCategory::ALL,
            'breadcrumbs' => [
                ['label' => 'Admin', 'url' => '/admin'],
                ['label' => 'Blog', 'url' => '/admin/blog'],
                ['label' => 'New Post'],
            ],
        ], $this->blogFormAssets()));
    }

    public function store(Request $request): View|RedirectResponse
    {
        if ($uploadError = $request->input('blog_image_upload_error')) {
            return $this->renderFormError('admin.blog.create', $request->all(), 'New Post', $uploadError);
        }

        $data = $this->extractPostData($request);
        $errors = $this->validatePost($data);
        if ($errors !== []) {
            return $this->renderFormError('admin.blog.create', $data, 'New Post', reset($errors));
        }

        $data['featuredImage'] = $this->resolveUploadedImage(
            $request->input('featuredImage'),
            $request->input('uploaded_featured_image')
        );

        try {
            $this->blogPostService->createPost($data);

            return redirect('/admin/blog/list');
        } catch (Throwable $e) {
            return $this->renderFormError('admin.blog.create', $data, 'New Post', $e->getMessage());
        }
    }

    public function edit(string $id): View|RedirectResponse
    {
        $post = $this->safeGetById($id);
        if (! $post) {
            return redirect('/admin/blog/list');
        }

        return view('admin.blog.edit', array_merge([
            'pageTitle' => 'Edit Post',
            'activePage' => 'blog',
            'post' => $post,
            'postId' => $id,
            'categories' => BlogCategory::ALL,
            'breadcrumbs' => [
                ['label' => 'Admin', 'url' => '/admin'],
                ['label' => 'Blog', 'url' => '/admin/blog'],
                ['label' => $post['title']],
            ],
        ], $this->blogFormAssets()));
    }

    public function update(Request $request, string $id): View|RedirectResponse
    {
        $existing = $this->safeGetById($id);
        if (! $existing) {
            return redirect('/admin/blog/list');
        }

        if ($uploadError = $request->input('blog_image_upload_error')) {
            return $this->renderFormError('admin.blog.edit', $existing, 'Edit Post', $uploadError, $id);
        }

        $data = $this->extractPostData($request);
        $errors = $this->validatePost($data);
        if ($errors !== []) {
            return $this->renderFormError('admin.blog.edit', array_merge($existing, $data), 'Edit Post', reset($errors), $id);
        }

        $newImage = $this->resolveUploadedImage($request->input('featuredImage'), $request->input('uploaded_featured_image'));
        if ($newImage !== null) {
            $data['featuredImage'] = $newImage;
            $this->deleteOldBlogImage($existing['featuredImage'] ?? '', $newImage);
        }

        try {
            $this->blogPostService->updatePost($id, $data);

            return redirect('/admin/blog/list');
        } catch (Throwable $e) {
            return $this->renderFormError('admin.blog.edit', array_merge($existing, $data), 'Edit Post', $e->getMessage(), $id);
        }
    }

    public function confirmDelete(string $id): View|RedirectResponse
    {
        $post = $this->safeGetById($id);
        if (! $post) {
            return redirect('/admin/blog/list');
        }

        return view('admin.blog.delete', [
            'pageTitle' => 'Delete Post',
            'activePage' => 'blog',
            'post' => $post,
            'breadcrumbs' => [
                ['label' => 'Admin', 'url' => '/admin'],
                ['label' => 'Blog', 'url' => '/admin/blog'],
                ['label' => 'Delete: '.$post['title']],
            ],
        ]);
    }

    public function destroy(string $id): RedirectResponse
    {
        $post = $this->safeGetById($id);
        $this->blogPostService->deletePost($id);

        if ($post) {
            $this->deleteOldBlogImage($post['featuredImage'] ?? '', '');
        }

        return redirect('/admin/blog/list');
    }

    /**
     * @return array<string, mixed>
     */
    private function extractPostData(Request $request): array
    {
        $rawTags = $request->input('tags', '');
        $tags = array_values(array_filter(array_map('trim', explode(',', (string) $rawTags))));

        $status = in_array($request->input('status'), self::STATUSES, true)
            ? $request->input('status')
            : 'draft';
        $publishDate = null;
        $publishMode = $request->input('publishMode', '');

        if ($publishMode === 'schedule' && $request->filled('publishDate')) {
            $status = 'draft';
            $publishDate = $request->input('publishDate');
        } elseif ($status === 'published') {
            $publishDate = $request->input('publishDate') ?: now()->toDateTimeString();
        } else {
            $publishDate = $request->input('publishDate') ?: null;
        }

        return [
            'title' => trim((string) $request->input('title', '')),
            'slug' => preg_replace('/[^a-z0-9\-]/', '', strtolower(trim((string) $request->input('slug', '')))),
            'excerpt' => trim((string) $request->input('excerpt', '')),
            'content' => (string) $request->input('content', ''),
            'author' => trim((string) $request->input('author', 'LFS Admin')),
            'category' => $request->input('category', ''),
            'tags' => $tags,
            'status' => $status,
            'featured' => $request->boolean('featured'),
            'publishDate' => $publishDate,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function validatePost(array $data): array
    {
        $errors = [];
        if ($data['title'] === '') {
            $errors[] = 'Title is required.';
        }
        if ($data['category'] !== '' && ! in_array($data['category'], BlogCategory::ALL, true)) {
            $errors[] = 'Invalid category.';
        }

        return $errors;
    }

    private function resolveUploadedImage(?string $bodyUrl, ?string $uploadedPath): ?string
    {
        if ($uploadedPath) {
            return $uploadedPath;
        }
        if (! $bodyUrl) {
            return null;
        }
        if (str_starts_with($bodyUrl, '/') && ! preg_match('/[<>"\'`\s]/', $bodyUrl)) {
            return $bodyUrl;
        }
        if (filter_var($bodyUrl, FILTER_VALIDATE_URL)) {
            $scheme = strtolower((string) parse_url($bodyUrl, PHP_URL_SCHEME));
            if (in_array($scheme, ['http', 'https'], true)) {
                return $bodyUrl;
            }
        }

        return null;
    }

    private function deleteOldBlogImage(string $oldImage, string $newImage): void
    {
        if ($oldImage && str_starts_with($oldImage, '/images/blog/') && $oldImage !== $newImage) {
            $path = public_path(ltrim($oldImage, '/'));
            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $post
     */
    private function renderFormError(string $view, ?array $post, string $pageTitle, string $error, ?string $postId = null): View
    {
        return view($view, array_merge([
            'pageTitle' => $pageTitle,
            'activePage' => 'blog',
            'post' => $post,
            'postId' => $postId,
            'categories' => BlogCategory::ALL,
            'error' => $error,
            'breadcrumbs' => [
                ['label' => 'Admin', 'url' => '/admin'],
                ['label' => 'Blog', 'url' => '/admin/blog'],
                ['label' => $pageTitle],
            ],
        ], $this->blogFormAssets()))->setStatusCode(422);
    }

    /**
     * @return array{extraStyles: string, extraScripts: string}
     */
    private function blogFormAssets(): array
    {
        return [
            'extraStyles' => '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2/dist/quill.snow.css">'
                .'<link rel="stylesheet" href="'.asset('admin/css/blog-form.css').'">',
            'extraScripts' => '<script src="https://cdn.jsdelivr.net/npm/dompurify@3/dist/purify.min.js"></script>'
                .'<script src="https://cdn.jsdelivr.net/npm/quill@2/dist/quill.js"></script>'
                .'<script src="'.asset('admin/js/blog-form.js').'"></script>',
        ];
    }

    private function safeGetById(string $id): ?array
    {
        try {
            return $this->blogPostService->getPostById($id);
        } catch (Throwable) {
            return null;
        }
    }
}
