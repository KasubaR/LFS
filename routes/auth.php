<?php

use App\Http\Controllers\Auth\AccountController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\MembershipApplicationController;
use App\Http\Controllers\Auth\MembershipPaymentController;
use App\Http\Controllers\Auth\MembershipPlanChangeController;
use App\Http\Controllers\Auth\MembershipRenewalController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\WishlistController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/create-account', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/create-account', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:3,1')
        ->name('register.store');

    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('login.store');

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:3,1')
        ->name('password.email');

    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.update');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])
        ->name('verification.notice');

    Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('/password/change', [ChangePasswordController::class, 'create'])
        ->middleware('verified')
        ->name('password.change');

    Route::post('/password/change', [ChangePasswordController::class, 'store'])
        ->middleware('verified')
        ->name('password.change.store');
});

// Signed verification link — works without an existing session and logs the user in.
Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/membership/apply', [MembershipApplicationController::class, 'create'])->name('membership.apply');
    Route::post('/membership/apply', [MembershipApplicationController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('membership.apply.store');
});

Route::middleware(['auth', 'verified', 'force.password.change', 'member'])->group(function (): void {
    Route::get('/account', [AccountController::class, 'show'])->name('account');
    Route::get('/account/card', [AccountController::class, 'card'])->name('account.card');
    Route::get('/account/card/pdf', [AccountController::class, 'downloadCard'])->name('account.card.pdf');
    Route::get('/account/orders', [AccountController::class, 'orders'])->name('account.orders');
    Route::get('/account/orders/{orderNumber}', [AccountController::class, 'orderShow'])
        ->where('orderNumber', '[A-Za-z0-9\-]+')
        ->name('account.orders.show');
    Route::get('/account/payments', [AccountController::class, 'payments'])->name('account.payments');
    Route::get('/account/payments/{payment}/receipt', [AccountController::class, 'downloadReceipt'])
        ->whereNumber('payment')
        ->name('account.payments.receipt');
    Route::get('/account/documents', [AccountController::class, 'documents'])->name('account.documents');
    Route::get('/account/documents/{id}/download', [AccountController::class, 'downloadDocument'])
        ->where('id', '[0-9a-fA-F\-]{36}')
        ->name('account.documents.download');
    Route::get('/account/wishlist', [AccountController::class, 'wishlist'])->name('account.wishlist');
    Route::post('/account/wishlist', [WishlistController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('account.wishlist.store');
    Route::delete('/account/wishlist/{productId}', [WishlistController::class, 'destroy'])
        ->where('productId', '[0-9a-fA-F\-]{36}')
        ->middleware('throttle:30,1')
        ->name('account.wishlist.destroy');
    Route::get('/account/settings', [AccountController::class, 'settings'])->name('account.settings');
    Route::get('/account/settings/personal', [AccountController::class, 'settingsPersonal'])
        ->name('account.settings.personal');
    Route::post('/account/settings/personal', [AccountController::class, 'updateSettings'])
        ->middleware('throttle:10,1')
        ->name('account.settings.personal.update');
    Route::get('/account/settings/password', [AccountController::class, 'settingsPassword'])
        ->name('account.settings.password');
    Route::post('/account/settings/password', [AccountController::class, 'updatePassword'])
        ->middleware('throttle:10,1')
        ->name('account.settings.password.update');
    // Keep legacy POST /account/settings working for older forms/tests.
    Route::post('/account/settings', [AccountController::class, 'updateSettings'])
        ->middleware('throttle:10,1')
        ->name('account.settings.update');

    Route::post('/account/payment/initiate', [MembershipPaymentController::class, 'initiate'])
        ->middleware('throttle:10,1')
        ->name('account.payment.initiate');

    Route::get('/account/payment/verify', [MembershipPaymentController::class, 'verify'])
        ->name('account.payment.verify');

    Route::post('/account/renew', [MembershipRenewalController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('account.renew');

    Route::post('/account/plan/change', [MembershipPlanChangeController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('account.plan.change');
});

Route::post('/account/payment/webhook', [MembershipPaymentController::class, 'webhook'])
    ->name('account.payment.webhook');
