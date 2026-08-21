<?php

use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AnnouncementController;
use App\Http\Controllers\Admin\ApiClientsController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BlogController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DocumentController;
use App\Http\Controllers\Admin\ElectionController;
use App\Http\Controllers\Admin\EventController;
use App\Http\Controllers\Admin\FaqController;
use App\Http\Controllers\Admin\GalleryController;
use App\Http\Controllers\Admin\MembersController;
use App\Http\Controllers\Admin\MessageController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\PromotionController;
use Illuminate\Support\Facades\Route;

$loginSlug = config('admin.login_slug');
$messageId = '[0-9]+|[a-f0-9\-]{36}';

Route::prefix('admin')->middleware(['admin', 'admin.ratelimit'])->group(function () use ($loginSlug, $messageId): void {

    // ── Auth (login slug + logout bypass the auth guard inside EnsureAdminAuthenticated) ──
    Route::get($loginSlug, [AuthController::class, 'showLogin']);
    Route::post($loginSlug, [AuthController::class, 'login']);
    Route::get($loginSlug.'/2fa', [AuthController::class, 'showTotpChallenge']);
    Route::post($loginSlug.'/2fa', [AuthController::class, 'verifyTotp']);
    Route::get($loginSlug.'/2fa/setup', [AuthController::class, 'showTotpSetup']);
    Route::post($loginSlug.'/2fa/setup', [AuthController::class, 'confirmTotpSetup']);
    Route::get('logout', [AuthController::class, 'logout']);

    // ── Root redirects ───────────────────────────────────────────────────────────────
    Route::redirect('/', '/admin/dashboard');
    Route::redirect('shop', '/admin/products');

    // ── Profile (any authenticated admin) ────────────────────────────────────────────
    Route::get('profile', [ProfileController::class, 'edit']);
    Route::post('profile', [ProfileController::class, 'update']);

    // ── Dashboard & activity ───────────────────────────────────────────────────────────
    Route::middleware('admin.can:dashboard,read')->group(function (): void {
        Route::get('dashboard', [DashboardController::class, 'index']);
        Route::get('activity', [DashboardController::class, 'activity']);
    });

    // ── Events ───────────────────────────────────────────────────────────────────────
    Route::prefix('events')->middleware('admin.can:events,read')->group(function (): void {
        Route::redirect('/', '/admin/events/list');
        Route::get('list', [EventController::class, 'index']);
        Route::get('create', [EventController::class, 'create']);
        Route::post('/', [EventController::class, 'store']);
        Route::get('{id}/edit', [EventController::class, 'edit'])->where('id', '[^/]+');
        Route::post('{id}/delete', [EventController::class, 'destroy'])->where('id', '[^/]+');
        Route::post('{id}', [EventController::class, 'update'])->where('id', '[^/]+');
    });

    // ── Blog ─────────────────────────────────────────────────────────────────────────
    Route::prefix('blog')->middleware('admin.can:blog,read')->group(function (): void {
        Route::redirect('/', '/admin/blog/list');
        Route::get('list', [BlogController::class, 'index']);
        Route::get('create', [BlogController::class, 'create']);
        Route::post('/', [BlogController::class, 'store']);
        Route::get('{id}/edit', [BlogController::class, 'edit'])->where('id', '[^/]+');
        Route::get('{id}/delete', [BlogController::class, 'confirmDelete'])->where('id', '[^/]+');
        Route::post('{id}/delete', [BlogController::class, 'destroy'])->where('id', '[^/]+');
        Route::post('{id}', [BlogController::class, 'update'])->where('id', '[^/]+');
    });

    // ── Gallery ──────────────────────────────────────────────────────────────────────
    Route::prefix('gallery')->middleware('admin.can:gallery,read')->group(function (): void {
        Route::redirect('/', '/admin/gallery/albums');

        Route::get('settings', [GalleryController::class, 'settings']);
        Route::post('settings', [GalleryController::class, 'updateSettings']);

        Route::get('upload', [GalleryController::class, 'uploadPage']);
        Route::post('upload', [GalleryController::class, 'handleUpload']);
        Route::post('cover-upload', [GalleryController::class, 'coverUpload']);

        Route::prefix('albums')->group(function (): void {
            Route::get('/', [GalleryController::class, 'albums']);
            Route::get('create', [GalleryController::class, 'createAlbum']);
            Route::post('/', [GalleryController::class, 'storeAlbum']);
            Route::get('{id}/edit', [GalleryController::class, 'editAlbum'])->where('id', '[^/]+');
            Route::get('{id}/manage', [GalleryController::class, 'manageAlbum'])->where('id', '[^/]+');
            Route::post('{id}/delete', [GalleryController::class, 'destroyAlbum'])->where('id', '[^/]+');
            Route::patch('{id}/feature', [GalleryController::class, 'toggleAlbumFeatured'])->where('id', '[^/]+');
            Route::post('{id}', [GalleryController::class, 'updateAlbum'])->where('id', '[^/]+');
        });

        Route::prefix('media')->group(function (): void {
            Route::post('reorder', [GalleryController::class, 'reorderMedia']);
            Route::post('bulk-delete', [GalleryController::class, 'bulkDeleteMedia']);
            Route::post('bulk-feature', [GalleryController::class, 'bulkFeatureMedia']);
            Route::post('bulk-move', [GalleryController::class, 'bulkMoveMedia']);

            Route::patch('{id}/caption', [GalleryController::class, 'updateCaption'])->where('id', '[^/]+');
            Route::patch('{id}/feature', [GalleryController::class, 'toggleMediaFeatured'])->where('id', '[^/]+');
            Route::patch('{id}/homepage-slider', [GalleryController::class, 'toggleMediaHomepageSlider'])->where('id', '[^/]+');
            Route::patch('{id}/event-highlight', [GalleryController::class, 'toggleMediaEventHighlight'])->where('id', '[^/]+');
            Route::delete('{id}', [GalleryController::class, 'deleteMedia'])->where('id', '[^/]+');
        });
    });

    // ── Products ─────────────────────────────────────────────────────────────────────
    Route::prefix('products')->middleware('admin.can:products,read')->group(function (): void {
        Route::get('/', [ProductController::class, 'index']);
        Route::get('create', [ProductController::class, 'create']);
        Route::post('/', [ProductController::class, 'store']);
        Route::get('{id}/edit', [ProductController::class, 'edit'])->where('id', '[^/]+');
        Route::get('{id}/preview', [ProductController::class, 'preview'])->where('id', '[^/]+');
        Route::post('{id}/delete', [ProductController::class, 'destroy'])->where('id', '[^/]+');
        Route::post('{id}', [ProductController::class, 'update'])->where('id', '[^/]+');
    });

    // ── Contact messages ─────────────────────────────────────────────────────────────
    Route::prefix('messages')->middleware('admin.can:messages,read')->group(function () use ($messageId): void {
        Route::get('/', [MessageController::class, 'index']);
        Route::get('{id}', [MessageController::class, 'show'])->where('id', $messageId);
        Route::get('{id}/reply', [MessageController::class, 'replyForm'])->where('id', $messageId);
        Route::post('{id}/reply', [MessageController::class, 'reply'])->where('id', $messageId);
        Route::post('{id}/status', [MessageController::class, 'updateStatus'])->where('id', $messageId);
        Route::post('{id}/delete', [MessageController::class, 'destroy'])->where('id', $messageId);
    });

    // ── FAQs ─────────────────────────────────────────────────────────────────────────
    Route::prefix('faqs')->middleware('admin.can:faqs,read')->group(function (): void {
        Route::get('/', [FaqController::class, 'index']);
        Route::get('create', [FaqController::class, 'create']);
        Route::post('create', [FaqController::class, 'store']);
        Route::get('{id}/edit', [FaqController::class, 'edit'])->where('id', '[0-9]+');
        Route::post('{id}/edit', [FaqController::class, 'update'])->where('id', '[0-9]+');
        Route::post('{id}/delete', [FaqController::class, 'destroy'])->where('id', '[0-9]+');
    });

    // ── Announcements ────────────────────────────────────────────────────────────────
    Route::prefix('announcements')->middleware('admin.can:announcements,read')->group(function (): void {
        Route::get('/', [AnnouncementController::class, 'index']);
        Route::get('create', [AnnouncementController::class, 'create']);
        Route::post('create', [AnnouncementController::class, 'store']);
        Route::get('{id}/edit', [AnnouncementController::class, 'edit'])->where('id', '[a-f0-9\-]{36}');
        Route::post('{id}/edit', [AnnouncementController::class, 'update'])->where('id', '[a-f0-9\-]{36}');
        Route::post('{id}/delete', [AnnouncementController::class, 'destroy'])->where('id', '[a-f0-9\-]{36}');
    });

    // ── Documents ────────────────────────────────────────────────────────────────────
    Route::prefix('documents')->middleware('admin.can:documents,read')->group(function (): void {
        Route::get('/', [DocumentController::class, 'index']);
        Route::get('create', [DocumentController::class, 'create']);
        Route::post('create', [DocumentController::class, 'store']);
        Route::get('{id}/edit', [DocumentController::class, 'edit'])->where('id', '[a-f0-9\-]{36}');
        Route::post('{id}/edit', [DocumentController::class, 'update'])->where('id', '[a-f0-9\-]{36}');
        Route::post('{id}/delete', [DocumentController::class, 'destroy'])->where('id', '[a-f0-9\-]{36}');
    });

    // ── API keys (partner event websites) ────────────────────────────────────────────
    Route::prefix('api-clients')->middleware('admin.can:api_clients,read')->group(function (): void {
        Route::get('/', [ApiClientsController::class, 'index']);
        Route::get('create', [ApiClientsController::class, 'create']);
        Route::post('create', [ApiClientsController::class, 'store']);
        Route::post('{id}/rotate', [ApiClientsController::class, 'rotate'])->where('id', '[0-9]+');
        Route::post('{id}/revoke', [ApiClientsController::class, 'revoke'])->where('id', '[0-9]+');
        Route::post('{id}/restore', [ApiClientsController::class, 'restore'])->where('id', '[0-9]+');
    });

    // ── Admin users (Super Admin) ────────────────────────────────────────────────────
    Route::prefix('users')->middleware('admin.can:admin_users,read')->group(function (): void {
        Route::get('/', [AdminUserController::class, 'index']);
        Route::get('create', [AdminUserController::class, 'create']);
        Route::post('create', [AdminUserController::class, 'store']);
        Route::get('{id}/edit', [AdminUserController::class, 'edit'])->where('id', '[0-9]+');
        Route::post('{id}/edit', [AdminUserController::class, 'update'])->where('id', '[0-9]+');
        Route::post('{id}/deactivate', [AdminUserController::class, 'deactivate'])->where('id', '[0-9]+');
        Route::post('{id}/activate', [AdminUserController::class, 'activate'])->where('id', '[0-9]+');
        Route::post('{id}/reset-totp', [AdminUserController::class, 'resetTotp'])->where('id', '[0-9]+');
    });

    // ── Members ──────────────────────────────────────────────────────────────────────
    Route::prefix('members')->middleware('admin.can:members,read')->group(function (): void {
        Route::get('/', [MembersController::class, 'index'])->name('admin.members.index');
        Route::get('list', [MembersController::class, 'list'])->name('admin.members.list');
        Route::get('import', [MembersController::class, 'importForm'])->name('admin.members.import');
        Route::post('import', [MembersController::class, 'import'])->name('admin.members.import.store');
        Route::post('import/{batchId}/rollback', [MembersController::class, 'rollback'])
            ->where('batchId', '[0-9]+')
            ->name('admin.members.import.rollback');
        Route::get('{id}', [MembersController::class, 'show'])
            ->where('id', '[0-9]+')
            ->name('admin.members.show');
        Route::post('{id}/memberships/{membershipId}/cancel', [MembersController::class, 'cancelMembership'])
            ->where('id', '[0-9]+')
            ->where('membershipId', '[a-f0-9\-]{36}')
            ->name('admin.members.cancel');
    });

    // ── Promotions ───────────────────────────────────────────────────────────────────
    Route::prefix('promotions')->middleware('admin.can:promotions,read')->group(function (): void {
        Route::get('/', [PromotionController::class, 'index']);
        Route::get('create', [PromotionController::class, 'create']);
        Route::post('create', [PromotionController::class, 'store']);
        Route::get('{id}/edit', [PromotionController::class, 'edit'])->where('id', '[0-9]+');
        Route::post('{id}/edit', [PromotionController::class, 'update'])->where('id', '[0-9]+');
        Route::post('{id}/delete', [PromotionController::class, 'destroy'])->where('id', '[0-9]+');
    });

    // ── Orders ───────────────────────────────────────────────────────────────────────
    Route::prefix('orders')->middleware('admin.can:orders,read')->group(function (): void {
        Route::get('/', [OrderController::class, 'index']);
        Route::get('{id}', [OrderController::class, 'show'])->where('id', '[0-9]+');
        Route::post('{id}/status', [OrderController::class, 'updateStatus'])->where('id', '[0-9]+');
    });

    // ── Elections ────────────────────────────────────────────────────────────────────
    Route::prefix('elections')->middleware('admin.can:elections,read')->group(function (): void {
        $uuid = '[a-f0-9\-]{36}';
        Route::get('/', [ElectionController::class, 'index']);
        Route::get('create', [ElectionController::class, 'create']);
        Route::post('create', [ElectionController::class, 'store']);
        Route::get('roll-template', [ElectionController::class, 'downloadRollTemplate']);
        Route::get('users/search', [ElectionController::class, 'searchUsers']);
        Route::get('{id}', [ElectionController::class, 'show'])->where('id', $uuid);
        Route::post('{id}', [ElectionController::class, 'update'])->where('id', $uuid);
        Route::post('{id}/positions', [ElectionController::class, 'storePosition'])->where('id', $uuid);
        Route::post('{id}/positions/{positionId}/delete', [ElectionController::class, 'destroyPosition'])
            ->where(['id' => $uuid, 'positionId' => $uuid]);
        Route::post('{id}/positions/{positionId}/candidates', [ElectionController::class, 'storeCandidate'])
            ->where(['id' => $uuid, 'positionId' => $uuid]);
        Route::post('{id}/candidates/{candidateId}/delete', [ElectionController::class, 'destroyCandidate'])
            ->where(['id' => $uuid, 'candidateId' => $uuid]);
        Route::post('{id}/roll/import', [ElectionController::class, 'importRoll'])->where('id', $uuid);
        Route::post('{id}/roll/lock', [ElectionController::class, 'lockRoll'])->where('id', $uuid);
        Route::post('{id}/roll/unlock', [ElectionController::class, 'unlockRoll'])->where('id', $uuid);
        Route::post('{id}/voters/{voterId}/exclude', [ElectionController::class, 'excludeVoter'])
            ->where(['id' => $uuid, 'voterId' => $uuid]);
        Route::post('{id}/voters/{voterId}/link', [ElectionController::class, 'linkVoter'])
            ->where(['id' => $uuid, 'voterId' => $uuid]);
        Route::post('{id}/proxies', [ElectionController::class, 'approveProxy'])->where('id', $uuid);
        Route::post('{id}/proxies/{proxyId}/revoke', [ElectionController::class, 'revokeProxy'])
            ->where(['id' => $uuid, 'proxyId' => $uuid]);
        Route::post('{id}/ballot/approve', [ElectionController::class, 'approveBallot'])->where('id', $uuid);
        Route::post('{id}/ballot/unlock', [ElectionController::class, 'unlockBallot'])->where('id', $uuid);
        Route::post('{id}/early-open-override', [ElectionController::class, 'earlyOpenOverride'])->where('id', $uuid);
        Route::post('{id}/open', [ElectionController::class, 'open'])->where('id', $uuid);
        Route::post('{id}/extend', [ElectionController::class, 'extend'])->where('id', $uuid);
        Route::post('{id}/close', [ElectionController::class, 'close'])->where('id', $uuid);
        Route::post('{id}/certify', [ElectionController::class, 'certify'])->where('id', $uuid);
        Route::post('{id}/lock', [ElectionController::class, 'permanentLock'])->where('id', $uuid);
        Route::post('{id}/quorum/confirm', [ElectionController::class, 'confirmQuorum'])->where('id', $uuid);
        Route::get('{id}/certificate', [ElectionController::class, 'certificate'])->where('id', $uuid);
        Route::get('{id}/participation', [ElectionController::class, 'participation'])->where('id', $uuid);
        Route::post('{id}/complaints', [ElectionController::class, 'storeComplaint'])->where('id', $uuid);
    });
});
