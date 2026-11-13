<?php

use App\Http\Controllers\BlogController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CookieController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\GalleryController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MembershipVerifyController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ShopController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public routes (matches legacy PHP front router)
|--------------------------------------------------------------------------
*/

Route::get('/', [HomeController::class, 'index']);

Route::get('/events', [EventController::class, 'index']);
Route::get('/events/{slug}', [EventController::class, 'show'])->where('slug', '[^/]+');

// ── Event tickets (paid events) ──────────────────────────────────────────────
Route::get('/events/{slug}/tickets', [TicketController::class, 'checkout'])->where('slug', '[^/]+');
Route::post('/events/{slug}/tickets/quote', [TicketController::class, 'quote'])
    ->where('slug', '[^/]+')->middleware('throttle:30,1');
Route::post('/events/{slug}/tickets/order', [TicketController::class, 'order'])
    ->where('slug', '[^/]+')->middleware('throttle:10,1');

Route::get('/tickets/find', [TicketController::class, 'findForm']);
Route::post('/tickets/find', [TicketController::class, 'find'])->middleware('throttle:5,1');
Route::get('/tickets/{orderNumber}', [TicketController::class, 'show'])
    ->where('orderNumber', '[^/]+')->name('tickets.show');
Route::get('/tickets/{orderNumber}/status', [TicketController::class, 'status'])
    ->where('orderNumber', '[^/]+')->middleware('throttle:60,1');

Route::get('/news', [BlogController::class, 'index']);
Route::get('/news/{slug}', [BlogController::class, 'show'])->where('slug', '[^/]+');

Route::get('/about', [PageController::class, 'about']);
Route::get('/privacy', [PageController::class, 'privacy']);

Route::get('/membership/verify/{token}', [MembershipVerifyController::class, 'show'])
    ->where('token', '[0-9a-fA-F\-]{36}')
    ->name('membership.verify');

Route::get('/contact', [ContactController::class, 'show']);
Route::post('/contact', [ContactController::class, 'store']);

Route::get('/gallery', [GalleryController::class, 'index']);
Route::get('/gallery/{id}', [GalleryController::class, 'show'])->where('id', '[^/]+');

Route::prefix('cookies')->group(function (): void {
    Route::post('/consent', [CookieController::class, 'consent']);
    Route::post('/prefs', [CookieController::class, 'prefs']);
    Route::post('/withdraw', [CookieController::class, 'withdraw']);
    Route::get('/status', [CookieController::class, 'status']);
});

Route::prefix('shop')->group(function (): void {
    Route::get('/', [ShopController::class, 'index']);
    Route::get('/cart', [ShopController::class, 'cart']);
    Route::get('/checkout', [ShopController::class, 'checkout']);
    Route::get('/product/{slug}', [ShopController::class, 'show'])->where('slug', '[^/]+');

    Route::post('/cart/add', [ShopController::class, 'addToCart']);
    Route::post('/cart/update', [ShopController::class, 'updateCart']);
    Route::post('/cart/remove', [ShopController::class, 'removeFromCart']);
    // Each attempt can probe for a valid code, so cap them.
    Route::post('/cart/promo', [ShopController::class, 'applyPromo'])->middleware('throttle:10,1');
    Route::post('/cart/promo/remove', [ShopController::class, 'removePromo']);

    Route::post('/checkout/place-order', [OrderController::class, 'placeOrder'])
        ->middleware('throttle:10,1');
    Route::get('/checkout/verify', [OrderController::class, 'verifyPayment'])
        ->middleware('throttle:30,1');
    // Lenco calls this from a few fixed servers; the cap only stops a flood of forged requests.
    Route::post('/checkout/webhook', [OrderController::class, 'handleWebhook'])
        ->middleware('throttle:120,1');

    Route::get('/order/{orderNumber}', [OrderController::class, 'orderConfirmation'])
        ->where('orderNumber', '[^/]+');
});

Route::fallback([PageController::class, 'notFound']);
