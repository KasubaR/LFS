<?php

use App\Http\Controllers\Api\Admin\ActivityController;
use App\Http\Controllers\Api\Admin\StatsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin JSON API (session auth via EnsureAdminAuthenticated)
|--------------------------------------------------------------------------
|
| Mount: /api/admin/*
| Matches legacy src/admin/routes/api.php
|
*/

Route::prefix('admin')->group(function (): void {
    Route::get('stats', [StatsController::class, 'index']);
    Route::get('activity', [ActivityController::class, 'index']);
});
