<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;

trait ProvidesAuthViews
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function authViewData(array $data = []): array
    {
        return array_merge([
            'bodyClass' => 'page-no-hero',
            'extraStyles' => '<link rel="stylesheet" href="'.asset('css/auth.css').'">',
            'status' => session()->pull('auth_status'),
        ], $data);
    }

    /**
     * Shared view data for every tab inside the member account hub
     * (dashboard, orders, documents, elections, settings, …) — pulls in
     * dashboard.css and the account nav script on top of the base auth
     * chrome so every tab shares the same look.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function accountViewData(array $data, User $user): array
    {
        $viewData = $this->authViewData(array_merge([
            'page' => 'auth',
            'user' => $user,
            'membership' => $data['membership'] ?? $this->membershipService->resolveDisplayMembership((int) $user->id),
            'bodyClass' => 'page-no-hero page-no-nav',
            'hideNavbar' => true,
        ], $data));

        $viewData['extraStyles'] .= '<link rel="stylesheet" href="'.asset('css/dashboard.css').'">';
        $viewData['extraScripts'] = ($viewData['extraScripts'] ?? '')
            .'<script src="'.asset('js/account-nav.js').'"></script>';

        return $viewData;
    }

    /**
     * Auth entry / sign-up flow pages — no top navbar, page-header hero only.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function signupViewData(array $data = []): array
    {
        return $this->authViewData(array_merge([
            'bodyClass' => 'page-no-hero page-no-nav',
            'hideNavbar' => true,
            'hideFooter' => true,
        ], $data));
    }
}
