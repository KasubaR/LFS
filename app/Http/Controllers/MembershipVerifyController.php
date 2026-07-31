<?php

namespace App\Http\Controllers;

use App\Models\Membership;
use Illuminate\Contracts\View\View;

class MembershipVerifyController extends Controller
{
    public function show(string $token): View
    {
        $membership = Membership::query()
            ->with(['user.satellite', 'plan'])
            ->where('public_token', $token)
            ->first();

        if ($membership === null || $membership->user === null) {
            abort(404);
        }

        return view('pages.membership-verify', [
            'title' => 'Membership Verification',
            'description' => 'Verify an LFS membership card.',
            'page' => 'membership-verify',
            'bodyClass' => 'page-no-hero page-no-nav page-membership-verify',
            'hideNavbar' => true,
            'membership' => $membership,
            'member' => $membership->user,
            'displayStatus' => $membership->cardDisplayStatus(),
            'isActive' => $membership->isCardActive(),
            'extraStyles' => '<link rel="stylesheet" href="'.asset('css/membership-card.css').'">',
        ]);
    }
}
