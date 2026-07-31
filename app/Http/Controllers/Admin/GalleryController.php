<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Response;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Services\GalleryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use RuntimeException;
use Throwable;

class GalleryController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private readonly GalleryService $galleryService,
    ) {}

    public function albums(Request $request): View
    {
        $query = array_filter([
            'year' => $request->query('year', ''),
            'category' => $request->query('category', ''),
            'search' => $request->query('search', ''),
        ], fn ($v) => $v !== '');

        try {
            $albums = $this->galleryService->getAlbums($query);
            $stats = [
                'totalAlbums' => count($albums),
                'totalMedia' => $this->galleryService->countMedia(),
                'featuredCount' => $this->galleryService->countFeaturedAlbums(),
            ];
            $galleryError = null;
        } catch (Throwable $e) {
            Log::error('[LFS Admin Gallery] albums error: '.$e->getMessage());
            $albums = [];
            $stats = ['totalAlbums' => 0, 'totalMedia' => 0, 'featuredCount' => 0];
            $galleryError = 'Unable to load albums. Please try again later.';
        }

        return view('admin.gallery.albums', [
            'pageTitle' => 'Gallery Albums',
            'activePage' => 'gallery',
            'albums' => $albums,
            'stats' => $stats,
            'galleryError' => $galleryError,
            'categories' => ['Race', 'Training', 'LSD', 'Social'],
            'filterYear' => $request->query('year', ''),
            'filterCategory' => $request->query('category', ''),
            'searchQuery' => $request->query('search', ''),
        ]);
    }

    public function createAlbum(): View
    {
        return view('admin.gallery.album-form', [
            'pageTitle' => 'Create Album',
            'activePage' => 'gallery',
            'album' => null,
            'isEdit' => false,
        ]);
    }

    public function storeAlbum(Request $request): RedirectResponse
    {
        try {
            $album = $this->galleryService->createAlbum($this->normaliseAlbumPostData($request->all()));
            if (! empty($album['coverImage']) && (int) ($album['mediaCount'] ?? 0) === 0) {
                $this->ensureCoverAsMedia($album);
            }

            return redirect('/admin/gallery/albums/'.$album['id'].'/edit');
        } catch (Throwable $e) {
            Log::error('[LFS Admin Gallery] storeAlbum error: '.$e->getMessage());

            return redirect('/admin/gallery/albums?error=album_create_failed');
        }
    }

    public function editAlbum(string $id): View|RedirectResponse
    {
        try {
            $album = $this->galleryService->getAlbumById($id);
        } catch (Throwable $e) {
            Log::error('[LFS Admin Gallery] editAlbum error: '.$e->getMessage());
            $album = null;
        }

        if (! $album) {
            return redirect('/admin/gallery/albums');
        }

        return view('admin.gallery.album-form', [
            'pageTitle' => 'Edit Album',
            'activePage' => 'gallery',
            'album' => $album,
            'isEdit' => true,
        ]);
    }

    public function manageAlbum(string $id): View|RedirectResponse
    {
        try {
            $album = $this->galleryService->getAlbumById($id);
            if (! $album) {
                return redirect('/admin/gallery/albums');
            }
            $media = $this->galleryService->getMediaByAlbumId($id, 'newest');
        } catch (Throwable $e) {
            Log::error('[LFS Admin Gallery] manageAlbum error: '.$e->getMessage());

            return redirect('/admin/gallery/albums');
        }

        return view('admin.gallery.manage', [
            'pageTitle' => 'Manage Album — '.($album['title'] ?? ''),
            'activePage' => 'gallery',
            'album' => $album,
            'media' => $media,
            'stats' => ['mediaCount' => count($media)],
        ]);
    }

    public function updateAlbum(Request $request, string $id): RedirectResponse
    {
        try {
            $album = $this->galleryService->updateAlbum($id, $this->normaliseAlbumPostData($request->all()));
            if (is_array($album) && ! empty($album['coverImage']) && (int) ($album['mediaCount'] ?? 0) === 0) {
                $this->ensureCoverAsMedia($album);
            }
        } catch (Throwable $e) {
            Log::error('[LFS Admin Gallery] updateAlbum error: '.$e->getMessage());

            return redirect('/admin/gallery/albums/'.$id.'/edit');
        }

        return redirect('/admin/gallery/albums/'.$id.'/manage');
    }

    public function destroyAlbum(string $id): RedirectResponse
    {
        try {
            $this->galleryService->deleteMediaByAlbumId($id);
            $this->galleryService->deleteAlbum($id);
        } catch (Throwable $e) {
            Log::error('[LFS Admin Gallery] destroyAlbum error: '.$e->getMessage());
        }

        return redirect('/admin/gallery/albums');
    }

    public function toggleAlbumFeatured(string $id): Response
    {
        try {
            $album = $this->galleryService->getAlbumById($id);
            if ($album) {
                $this->galleryService->updateAlbum($id, ['featured' => ! $album['featured']]);
            }
        } catch (Throwable $e) {
            Log::error('[LFS Admin Gallery] toggleAlbumFeatured error: '.$e->getMessage());
        }

        return response()->noContent();
    }

    public function uploadPage(): View
    {
        try {
            $albums = $this->galleryService->getAlbumsForUpload();
        } catch (Throwable $e) {
            Log::error('[LFS Admin Gallery] uploadPage error: '.$e->getMessage());
            $albums = [];
        }

        return view('admin.gallery.upload', [
            'pageTitle' => 'Upload Media',
            'activePage' => 'gallery',
            'albums' => $albums,
        ]);
    }

    public function handleUpload(): JsonResponse
    {
        return $this->jsonResponse([
            'success' => false,
            'message' => 'Upload handler not yet implemented in gallery controller.',
        ]);
    }

    public function settings(): View
    {
        $bannerImage = null;
        $error = null;
        try {
            $bannerImage = $this->galleryService->getGalleryBanner();
        } catch (Throwable $e) {
            Log::error('[LFS Admin Gallery] settings error: '.$e->getMessage());
            $error = 'Unable to load current settings.';
        }

        return view('admin.gallery.settings', [
            'pageTitle' => 'Gallery Settings',
            'activePage' => 'gallery',
            'breadcrumbs' => [
                ['label' => 'Gallery', 'url' => '/admin/gallery'],
                ['label' => 'Settings'],
            ],
            'bannerImage' => $bannerImage,
            'error' => $error,
        ]);
    }

    public function updateSettings(Request $request): View|RedirectResponse
    {
        $bodyUrl = trim((string) $request->input('bannerImageUrl', ''));
        $removeBanner = $request->boolean('removeBanner');

        try {
            $banner = $removeBanner ? null : $this->resolveUploadedGalleryBanner($bodyUrl, $request);
            $this->galleryService->setGalleryBanner($banner);
        } catch (Throwable $e) {
            Log::error('[LFS Admin Gallery] updateSettings error: '.$e->getMessage());

            return view('admin.gallery.settings', [
                'pageTitle' => 'Gallery Settings',
                'activePage' => 'gallery',
                'breadcrumbs' => [
                    ['label' => 'Gallery', 'url' => '/admin/gallery'],
                    ['label' => 'Settings'],
                ],
                'bannerImage' => $bodyUrl !== '' ? $bodyUrl : null,
                'error' => 'Failed to save gallery settings. Please try again.',
            ]);
        }

        return redirect('/admin/gallery/settings');
    }

    public function updateCaption(Request $request, string $id): JsonResponse
    {
        try {
            $media = $this->galleryService->updateMedia($id, ['caption' => trim((string) $request->input('caption', ''))], ['new' => true]);
        } catch (Throwable $e) {
            Log::error('[LFS Admin Gallery] updateCaption error: '.$e->getMessage());

            return $this->jsonResponse(['success' => false, 'message' => 'Failed to update caption.'], 500);
        }

        return $this->jsonResponse(['success' => true, 'media' => $media]);
    }

    public function toggleMediaFeatured(string $id): JsonResponse
    {
        return $this->toggleMediaFlag($id, 'featured', 'featured');
    }

    public function toggleMediaHomepageSlider(string $id): JsonResponse
    {
        return $this->toggleMediaFlag($id, 'homepageSlider', 'homepageSlider');
    }

    public function toggleMediaEventHighlight(string $id): JsonResponse
    {
        return $this->toggleMediaFlag($id, 'eventHighlight', 'eventHighlight');
    }

    public function deleteMedia(string $id): Response
    {
        try {
            $media = $this->galleryService->getMediaById($id);
            $this->galleryService->deleteMedia($id);
            if ($media && ! empty($media['albumId'])) {
                $album = $this->galleryService->getAlbumById($media['albumId']);
                if ($album && ! empty($album['coverImage'])) {
                    $urls = array_values(is_array($media['urls'] ?? null) ? $media['urls'] : []);
                    if (in_array($album['coverImage'], $urls, true)) {
                        $this->galleryService->updateAlbum($album['id'], ['coverImage' => '']);
                    }
                }
            }
        } catch (Throwable $e) {
            Log::error('[LFS Admin Gallery] deleteMedia error: '.$e->getMessage());
        }

        return response()->noContent();
    }

    public function reorderMedia(Request $request): JsonResponse
    {
        $ids = $request->input('ids', []);
        if (! is_array($ids)) {
            $ids = [];
        }

        try {
            $order = 0;
            foreach ($ids as $id) {
                $this->galleryService->updateMedia((string) $id, ['sortOrder' => $order]);
                $order++;
            }
        } catch (Throwable $e) {
            Log::error('[LFS Admin Gallery] reorderMedia error: '.$e->getMessage());

            return $this->jsonResponse(['success' => false], 500);
        }

        return $this->jsonResponse(['success' => true]);
    }

    public function bulkDeleteMedia(Request $request): RedirectResponse
    {
        $ids = $request->input('ids', []);
        if (! is_array($ids)) {
            $ids = [];
        }

        try {
            $mediaList = $this->galleryService->findMediaByIds($ids);
            $this->galleryService->deleteManyMedia($ids);
            $this->clearCoversForDeletedMedia($mediaList);
        } catch (Throwable $e) {
            Log::error('[LFS Admin Gallery] bulkDeleteMedia error: '.$e->getMessage());
        }

        return redirect()->back('/admin/gallery/albums');
    }

    public function bulkFeatureMedia(Request $request): RedirectResponse
    {
        $ids = $request->input('ids', []);
        $featured = $request->boolean('featured', true);
        if (! is_array($ids)) {
            $ids = [];
        }

        try {
            $this->galleryService->updateManyMedia($ids, ['featured' => $featured]);
        } catch (Throwable $e) {
            Log::error('[LFS Admin Gallery] bulkFeatureMedia error: '.$e->getMessage());
        }

        return redirect()->back('/admin/gallery/albums');
    }

    public function bulkMoveMedia(Request $request): RedirectResponse
    {
        $ids = $request->input('ids', []);
        $albumId = (string) $request->input('albumId', '');
        if (! is_array($ids)) {
            $ids = [];
        }

        if ($albumId === '' || $ids === []) {
            return redirect()->back('/admin/gallery/albums');
        }

        try {
            $this->galleryService->updateManyMedia($ids, ['albumId' => $albumId]);
        } catch (Throwable $e) {
            Log::error('[LFS Admin Gallery] bulkMoveMedia error: '.$e->getMessage());
        }

        return redirect()->back('/admin/gallery/albums');
    }

    /**
     * @param  array<string, mixed>  $album
     */
    private function ensureCoverAsMedia(array $album): void
    {
        try {
            $coverUrl = trim($album['coverImage'] ?? '');
            $albumId = (string) ($album['id'] ?? '');
            if ($coverUrl === '' || $albumId === '') {
                return;
            }

            $filename = basename($coverUrl);
            $urls = [
                'original' => $coverUrl,
                'large' => $coverUrl,
                'medium' => $coverUrl,
                'thumbnail' => $coverUrl,
            ];

            $this->galleryService->createMedia([
                'albumId' => $albumId,
                'filename' => $filename,
                'storedName' => $filename,
                'type' => 'photo',
                'mimetype' => null,
                'size' => null,
                'urls' => $urls,
                'caption' => ($album['title'] ?? '') !== '' ? ($album['title'].' cover') : 'Album cover',
                'tags' => [],
                'featured' => false,
                'sortOrder' => 0,
            ]);
            $this->galleryService->incrementAlbumMediaCount($albumId, 1);
        } catch (Throwable $e) {
            Log::error('[LFS Admin Gallery] ensureCoverAsMedia error: '.$e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     */
    private function normaliseAlbumPostData(array $post): array
    {
        $tagsRaw = trim($post['tags'] ?? '');
        $tags = $tagsRaw !== '' ? preg_split('/\s*,\s*/', $tagsRaw) : [];

        return [
            'title' => trim($post['title'] ?? ''),
            'description' => trim($post['description'] ?? ''),
            'category' => trim($post['category'] ?? ''),
            'date' => trim($post['date'] ?? ''),
            'location' => trim($post['location'] ?? ''),
            'event' => trim($post['event'] ?? ''),
            'tags' => $tags,
            'coverImage' => trim($post['coverImage'] ?? ''),
            'externalUrl' => trim($post['externalUrl'] ?? ''),
            'mediaCount' => (int) ($post['mediaCount'] ?? 0),
            'featured' => ! empty($post['featured']),
            'homepageSlider' => ! empty($post['homepageSlider']),
            'eventHighlight' => ! empty($post['eventHighlight']),
            'sortPriority' => (int) ($post['sortPriority'] ?? 0),
        ];
    }

    private function resolveUploadedGalleryBanner(?string $bodyUrl, Request $request): ?string
    {
        if ($request->hasFile('bannerImageFile')) {
            $file = $request->file('bannerImageFile');
            $ext = strtolower($file->getClientOriginalExtension());
            $safeExt = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) ? $ext : 'jpg';
            $destDir = public_path('images/gallery');
            if (! is_dir($destDir) && ! mkdir($destDir, 0755, true) && ! is_dir($destDir)) {
                throw new RuntimeException('Could not create gallery images directory.');
            }
            $hash = sha1_file($file->getRealPath());
            $filename = 'gallery-'.$hash.'.'.$safeExt;
            $file->move($destDir, $filename);

            return '/images/gallery/'.$filename;
        }

        $bodyUrl = trim((string) ($bodyUrl ?? ''));

        return $bodyUrl !== '' ? $bodyUrl : null;
    }

    private function toggleMediaFlag(string $id, string $field, string $jsonKey): JsonResponse
    {
        try {
            $media = $this->galleryService->getMediaById($id);
            if (! $media) {
                return $this->jsonResponse(['success' => false, $jsonKey => false], 404);
            }
            $newState = ! ($media[$field] ?? false);
            $this->galleryService->updateMedia($id, [$field => $newState]);

            return $this->jsonResponse(['success' => true, $jsonKey => $newState]);
        } catch (Throwable $e) {
            Log::error('[LFS Admin Gallery] toggleMediaFlag error: '.$e->getMessage());

            return $this->jsonResponse(['success' => false, 'message' => 'Failed to update media.', $jsonKey => false], 500);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $mediaList
     */
    private function clearCoversForDeletedMedia(array $mediaList): void
    {
        $albumsToCheck = [];
        foreach ($mediaList as $m) {
            if (! empty($m['albumId'])) {
                $albumsToCheck[(string) $m['albumId']][] = $m;
            }
        }

        foreach ($albumsToCheck as $albumId => $mediaItems) {
            $album = $this->galleryService->getAlbumById($albumId);
            if (! $album || empty($album['coverImage'])) {
                continue;
            }
            foreach ($mediaItems as $m) {
                $urls = array_values(is_array($m['urls'] ?? null) ? $m['urls'] : []);
                if (in_array($album['coverImage'], $urls, true)) {
                    $this->galleryService->updateAlbum($album['id'], ['coverImage' => '']);
                    break;
                }
            }
        }
    }
}
