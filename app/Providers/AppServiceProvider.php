<?php

namespace App\Providers;

use App\Services\AdminPermissionService;
use App\Services\LencoService;
use App\Services\WishlistService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LencoService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        require_once app_path('Support/lfs_helpers.php');

        // Some hosts still run MySQL/MariaDB without innodb_large_prefix, which
        // caps indexed key length at 767 bytes — too small for a varchar(255)
        // column under utf8mb4 (1020 bytes). 191 chars keeps every index under
        // that limit regardless of host config.
        Schema::defaultStringLength(191);

        // Generated URLs (redirects, asset links, email links) always come out
        // https:// in production — including the admin/elections login and
        // 2FA redirects — even if a proxy in front of the app reports http.
        // This doesn't reject a plain-HTTP request itself (that's a host/proxy
        // config concern, see docs/ELECTIONS_RUNBOOK.md), it just stops the
        // app from ever generating an insecure link.
        if (app()->environment('production')) {
            URL::forceScheme('https');
        }

        Password::defaults(fn () => Password::min(8)->mixedCase()->numbers()->symbols());

        RateLimiter::for('admin', function (Request $request) {
            $sessionId = $request->session()->getId();

            return Limit::perMinute(60)->by('lfs_admin_post:'.sha1($sessionId !== '' ? $sessionId : (string) $request->ip()));
        });

        View::composer(['pages.shop', 'pages.productDetails'], function ($view): void {
            $ids = [];
            if (Auth::check()) {
                $ids = app(WishlistService::class)->productIdsForUser((int) Auth::id());
            }

            $view->with('wishlistProductIds', $ids);
        });

        View::composer(['layouts.admin', 'admin.*'], function ($view): void {
            $data = $view->getData();
            if (! empty($data['authPage'])) {
                return;
            }

            $canRead = [];
            $canWrite = [];
            foreach (config('admin_permissions.sections', []) as $section) {
                $canRead[$section] = false;
                $canWrite[$section] = false;
            }

            $adminUser = $data['adminUser'] ?? [
                'id' => 0,
                'name' => 'Admin',
                'email' => '',
                'role' => '',
                'roleLabel' => '',
            ];
            $adminIsSatelliteAdmin = (bool) ($data['adminIsSatelliteAdmin'] ?? false);
            $flash = (array) ($data['flash'] ?? []);

            $request = request();
            if ($request->hasSession()) {
                $permissions = app(AdminPermissionService::class);
                $admin = $permissions->currentAdmin($request);

                foreach (config('admin_permissions.sections', []) as $section) {
                    $canRead[$section] = $permissions->can($admin, $section, AdminPermissionService::LEVEL_READ);
                    $canWrite[$section] = $permissions->can($admin, $section, AdminPermissionService::LEVEL_WRITE);
                }

                if ($admin) {
                    $adminUser = $admin->toDisplayArray();
                    $adminIsSatelliteAdmin = $admin->isSatelliteAdministrator();
                }

                $flash = array_merge($flash, (array) $request->session()->get('flash', []));
            } elseif (isset($data['adminCanRead']) && is_array($data['adminCanRead'])) {
                $canRead = $data['adminCanRead'];
                $canWrite = is_array($data['adminCanWrite'] ?? null) ? $data['adminCanWrite'] : $canWrite;
            }

            $view->with([
                'adminUser' => $adminUser,
                'adminCanRead' => $canRead,
                'adminCanWrite' => $canWrite,
                'adminIsSatelliteAdmin' => $adminIsSatelliteAdmin,
                'flash' => $flash,
            ]);
        });
    }
}
