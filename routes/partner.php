<?php

use App\Enums\ApiScope;
use App\Http\Controllers\Api\V1\MemberVerificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Partner API v1 (Bearer key auth, stateless)
|--------------------------------------------------------------------------
|
| Mount: /api/v1/*  (see bootstrap/app.php)
|
| Consumed by LFS event websites to apply member discounts at checkout. This
| group is deliberately outside the `web` middleware group: no session, no
| CSRF, no admin login. Authentication is the Bearer API key only.
|
| See docs/api/PARTNER_INTEGRATION.md and docs/api/openapi.yaml.
|
*/

Route::middleware(['api.log', 'api.client', 'api.ratelimit'])->group(function (): void {

    Route::prefix('members')->group(function (): void {

        Route::post('verify', [MemberVerificationController::class, 'verify'])
            ->middleware('api.scope:'.ApiScope::MembersVerify);

        Route::get('token/{token}', [MemberVerificationController::class, 'showByToken'])
            ->middleware('api.scope:'.ApiScope::MembersReadToken)
            ->where('token', '[a-f0-9\-]{36}');
    });
});
