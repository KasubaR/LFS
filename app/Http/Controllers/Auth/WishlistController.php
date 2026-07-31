<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\WishlistService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WishlistController extends Controller
{
    public function __construct(
        private readonly WishlistService $wishlistService,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'uuid'],
        ]);

        $added = $this->wishlistService->add((int) Auth::id(), $validated['product_id']);

        if (! $added) {
            return back()->withErrors(['_general' => 'That product could not be added to your wishlist.']);
        }

        return back()->with('auth_status', 'Added to your wishlist.');
    }

    public function destroy(string $productId): RedirectResponse
    {
        $this->wishlistService->remove((int) Auth::id(), $productId);

        return back()->with('auth_status', 'Removed from your wishlist.');
    }
}
