<?php

namespace App\Http\Controllers\Concerns;

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
        ], $data));
    }
}
