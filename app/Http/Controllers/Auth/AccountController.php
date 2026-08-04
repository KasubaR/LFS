<?php

namespace App\Http\Controllers\Auth;

use App\Enums\MembershipStatus;
use App\Enums\TShirtSize;
use App\Http\Controllers\Concerns\ProvidesAuthViews;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Models\Membership;
use App\Models\MembershipPayment;
use App\Models\User;
use App\Services\AnnouncementService;
use App\Services\DocumentService;
use App\Services\MembershipCardService;
use App\Services\MembershipPaymentService;
use App\Services\MembershipPlanService;
use App\Services\OrderService;
use App\Services\SatelliteService;
use App\Services\WishlistService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class AccountController extends Controller
{
    use ProvidesAuthViews;

    public function __construct(
        private readonly MembershipPlanService $planService,
        private readonly AnnouncementService $announcementService,
        private readonly OrderService $orderService,
        private readonly WishlistService $wishlistService,
        private readonly SatelliteService $satelliteService,
        private readonly MembershipCardService $membershipCardService,
        private readonly MembershipPaymentService $paymentService,
        private readonly DocumentService $documentService,
    ) {}

    public function show(): View|RedirectResponse
    {
        $user = $this->accountUser();
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        $membership = $this->resolveDisplayMembership((int) $user->id);

        $canContinuePayment = $membership !== null
            && in_array($membership->status, [MembershipStatus::Draft, MembershipStatus::PendingPayment], true);

        // A membership only ever gets a number once its first payment is
        // confirmed (see MembershipService::activateOnPayment), so this also
        // doubles as "has this member ever paid" — true for active/expired
        // members, false for anyone still on an unpaid draft/pending application.
        $hasPaidMembership = $membership !== null && $membership->membership_number !== null;
        $cardData = $this->membershipCardViewData($membership);

        $viewData = $this->accountViewData(array_merge([
            'title' => 'My Dashboard',
            'description' => 'Your LFS member dashboard and membership status.',
            'activeTab' => 'dashboard',
            'membership' => $cardData['membership'] ?? $membership,
            'canContinuePayment' => $canContinuePayment,
            'canRenew' => $membership !== null && $membership->status === MembershipStatus::Expired,
            'hasPaidMembership' => $hasPaidMembership,
            'renewalPlans' => $this->planService->getActivePlans(),
            'changePlans' => $canContinuePayment ? $this->planService->getActivePlans() : [],
            'announcements' => $hasPaidMembership ? $this->announcementService->getActiveForMembers(5) : [],
            'extraScripts' => $canContinuePayment
                ? '<script src="'.asset('js/account-payment.js').'"></script>'
                : '',
        ], $cardData), $user);

        $viewData['extraStyles'] .= '<link rel="stylesheet" href="'.asset('css/membership-card.css').'">';

        return view('pages.auth.dashboard', $viewData);
    }

    public function card(): View|RedirectResponse
    {
        $user = $this->accountUser();
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        $membership = $this->resolveDisplayMembership((int) $user->id);
        $cardData = $this->membershipCardViewData($membership);

        $viewData = $this->accountViewData(array_merge([
            'title' => 'Membership Card',
            'description' => 'Your LFS digital membership card with QR code.',
            'activeTab' => 'dashboard',
            'membership' => $cardData['membership'] ?? $membership,
        ], $cardData), $user);

        $viewData['extraStyles'] .= '<link rel="stylesheet" href="'.asset('css/membership-card.css').'">';

        return view('pages.auth.account-card', $viewData);
    }

    public function downloadCard(): Response|RedirectResponse
    {
        $user = $this->accountUser();
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        $membership = $this->resolveDisplayMembership((int) $user->id);
        $cardData = $this->membershipCardViewData($membership);

        if (! $cardData['hasCard'] || $cardData['membership'] === null) {
            abort(404, 'Membership card is not available yet.');
        }

        $membership = $cardData['membership'];

        $pdf = Pdf::loadView('receipts.membership-card-pdf', [
            'user' => $user,
            'membership' => $membership,
            'displayStatus' => $cardData['displayStatus'],
            'expiryLabel' => $membership->expiry_date
                ? $membership->expiry_date->format('j M Y')
                : '—',
            'satelliteName' => $user->satellite?->name ?? '—',
            'planName' => $membership->plan?->name ?? '—',
            'qrDataUri' => $this->membershipCardService->qrPngDataUri($membership),
        ])->setPaper('a4');

        $filename = 'LFS-Membership-Card-'.($membership->membership_number ?? 'member').'.pdf';

        return $pdf->download($filename);
    }

    public function orders(): View|RedirectResponse
    {
        $user = $this->accountUser();
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        return view('pages.auth.account-orders', $this->accountViewData([
            'title' => 'My Orders',
            'description' => 'Your LFS shop order history.',
            'activeTab' => 'orders',
            'orders' => $this->orderService->listForMember((int) $user->id, (string) $user->email),
        ], $user));
    }

    public function orderShow(string $orderNumber): View|RedirectResponse
    {
        $user = $this->accountUser();
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        $order = $this->orderService->findForMember($orderNumber, (int) $user->id, (string) $user->email);
        if ($order === null) {
            abort(404, 'Order not found.');
        }

        return view('pages.auth.account-order-show', $this->accountViewData([
            'title' => 'Order '.$order['orderNumber'],
            'description' => 'Details for your LFS shop order.',
            'activeTab' => 'orders',
            'order' => $order,
        ], $user));
    }

    public function wishlist(): View|RedirectResponse
    {
        $user = $this->accountUser();
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        return view('pages.auth.account-wishlist', $this->accountViewData([
            'title' => 'My Wishlist',
            'description' => 'Products you have saved for later.',
            'activeTab' => 'wishlist',
            'wishlistItems' => $this->wishlistService->listForUser((int) $user->id),
        ], $user));
    }

    public function payments(): View|RedirectResponse
    {
        $user = $this->accountUser();
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        return view('pages.auth.account-payments', $this->accountViewData([
            'title' => 'Payment History',
            'description' => 'Your LFS membership payment history and receipts.',
            'activeTab' => 'payments',
            'payments' => $this->paymentService->listForUser((int) $user->id),
        ], $user));
    }

    public function downloadReceipt(MembershipPayment $payment): Response
    {
        $user = $this->accountUser();
        if ($user instanceof RedirectResponse) {
            abort(404);
        }

        $owned = $this->paymentService->findOwnedByUser((int) $payment->id, (int) $user->id);
        abort_if($owned === null, 404);
        abort_if((float) $owned->amount_paid <= 0, 404, 'No receipt available for this payment yet.');

        $pdf = Pdf::loadView('receipts.membership-pdf', [
            'payment' => $owned,
            'membership' => $owned->membership,
            'plan' => $owned->plan,
            'user' => $user,
            'issuer' => config('membership.issuer'),
        ])->setPaper('a4');

        $filename = 'LFS-Receipt-'.($owned->payment_reference ?: $owned->id).'.pdf';

        return $pdf->download($filename);
    }

    public function documents(): View|RedirectResponse
    {
        $user = $this->accountUser();
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        $membership = $this->resolveDisplayMembership((int) $user->id);
        $hasPaidMembership = $membership !== null && $membership->membership_number !== null;

        return view('pages.auth.account-documents', $this->accountViewData([
            'title' => 'Document Library',
            'description' => 'Download LFS constitution, policies, forms, and more.',
            'activeTab' => 'documents',
            'membership' => $membership,
            'hasPaidMembership' => $hasPaidMembership,
            'documentGroups' => $hasPaidMembership
                ? $this->documentService->getPublishedGroupedForMembers()
                : [],
        ], $user));
    }

    public function downloadDocument(string $id): BinaryFileResponse
    {
        $user = $this->accountUser();
        if ($user instanceof RedirectResponse) {
            abort(404);
        }

        $membership = $this->resolveDisplayMembership((int) $user->id);
        abort_if(
            $membership === null || $membership->membership_number === null,
            404,
            'Document library unlocks after membership payment is confirmed.'
        );

        $document = $this->documentService->findPublishedForDownload($id);
        abort_if($document === null, 404);

        $path = $this->documentService->absolutePath($document);
        abort_if($path === null, 404, 'Document file is missing.');

        return response()->download(
            $path,
            $document->original_filename ?: basename($path),
            [
                'Content-Type' => $document->mime_type ?: 'application/octet-stream',
            ]
        );
    }

    public function settings(): View|RedirectResponse
    {
        $user = $this->accountUser();
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        return view('pages.auth.account-settings', $this->accountViewData([
            'title' => 'Account Settings',
            'description' => 'Manage your LFS member profile and security.',
            'activeTab' => 'settings',
        ], $user));
    }

    public function settingsPersonal(): View|RedirectResponse
    {
        $user = $this->accountUser();
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        return view('pages.auth.account-settings-personal', $this->accountViewData([
            'title' => 'Personal Details',
            'description' => 'Update your name, email, and phone.',
            'activeTab' => 'settings',
            'satellites' => $this->satelliteService->getActiveSatellites(),
            'tShirtSizes' => TShirtSize::options(),
            'genderOptions' => [
                'male' => 'Male',
                'female' => 'Female',
                'other' => 'Other',
                'prefer_not_to_say' => 'Prefer not to say',
            ],
        ], $user));
    }

    public function updateSettings(UpdateProfileRequest $request): RedirectResponse
    {
        $user = Auth::user();
        $data = $request->validated();

        $avatarPath = $user->avatar_path;

        if (! empty($data['remove_avatar'])) {
            $this->deleteAvatarFile($avatarPath);
            $avatarPath = null;
        } elseif ($request->hasFile('avatar')) {
            $stored = $this->storeAvatar($request->file('avatar'), (int) $user->id);
            if ($stored !== null) {
                $this->deleteAvatarFile($avatarPath);
                $avatarPath = $stored;
            }
        }

        $user->forceFill([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'gender' => $data['gender'],
            'nationality' => $data['nationality'],
            'satellite_id' => $data['satellite_id'],
            'town' => $data['town'],
            't_shirt_size' => $data['t_shirt_size'],
            'avatar_path' => $avatarPath,
        ])->save();

        return redirect()
            ->route('account.settings.personal')
            ->with('auth_status', 'Your profile has been updated.');
    }

    private function storeAvatar(UploadedFile $file, int $userId): ?string
    {
        if (! $file->isValid()) {
            return null;
        }

        $dir = public_path('uploads/avatars/'.$userId);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return null;
        }

        $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $ext = 'jpg';
        }

        $filename = 'avatar_'.Str::lower(Str::random(12)).'.'.$ext;
        $file->move($dir, $filename);

        return '/uploads/avatars/'.$userId.'/'.$filename;
    }

    private function deleteAvatarFile(?string $path): void
    {
        if ($path === null || trim($path) === '') {
            return;
        }

        $relative = ltrim($path, '/');
        if (! str_starts_with($relative, 'uploads/avatars/')) {
            return;
        }

        $absolute = public_path($relative);
        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    public function settingsPassword(): View|RedirectResponse
    {
        $user = $this->accountUser();
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        return view('pages.auth.account-settings-password', $this->accountViewData([
            'title' => 'Change Password',
            'description' => 'Update your account password.',
            'activeTab' => 'settings',
        ], $user));
    }

    public function updatePassword(ChangePasswordRequest $request): RedirectResponse
    {
        $user = Auth::user();

        $user->forceFill([
            'password' => Hash::make($request->validated('password')),
            'must_change_password' => false,
        ])->save();

        return redirect()
            ->route('account.settings.password')
            ->with('auth_status', 'Your password has been updated.');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function accountViewData(array $data, User $user): array
    {
        $viewData = $this->authViewData(array_merge([
            'page' => 'auth',
            'user' => $user,
            'membership' => $data['membership'] ?? $this->resolveDisplayMembership((int) $user->id),
            'bodyClass' => 'page-no-hero page-no-nav',
            'hideNavbar' => true,
        ], $data));

        $viewData['extraStyles'] .= '<link rel="stylesheet" href="'.asset('css/dashboard.css').'">';
        $viewData['extraScripts'] = ($viewData['extraScripts'] ?? '')
            .'<script src="'.asset('js/account-nav.js').'"></script>';

        return $viewData;
    }

    private function accountUser(): User|RedirectResponse
    {
        $user = Auth::user();

        if (! $user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        if ($user->must_change_password) {
            return redirect()->route('password.change');
        }

        if (! Membership::query()->where('user_id', $user->id)->exists()) {
            return redirect()->route('membership.apply');
        }

        $user->load('satellite');

        return $user;
    }

    /**
     * @return array{
     *     membership: ?Membership,
     *     hasCard: bool,
     *     qrSvg: ?string,
     *     verifyUrl: ?string,
     *     displayStatus: ?string,
     *     isCardActive: bool
     * }
     */
    private function membershipCardViewData(?Membership $membership): array
    {
        $hasCard = $membership !== null
            && $membership->membership_number !== null
            && $membership->membership_number !== '';

        $qrSvg = null;
        $verifyUrl = null;
        $displayStatus = null;

        if ($hasCard && $membership !== null) {
            $membership = $this->membershipCardService->ensurePublicToken($membership);
            $qrSvg = $this->membershipCardService->qrSvg($membership);
            $verifyUrl = $membership->verificationUrl();
            $displayStatus = $membership->cardDisplayStatus();
        }

        return [
            'membership' => $membership,
            'hasCard' => $hasCard,
            'qrSvg' => $qrSvg,
            'verifyUrl' => $verifyUrl,
            'displayStatus' => $displayStatus,
            'isCardActive' => $membership?->isCardActive() ?? false,
        ];
    }

    /**
     * Pick the membership row to show on the account page. `created_at` alone is
     * not a reliable tiebreaker — a renewal's new PendingPayment row can share the
     * same second-precision timestamp as the Expired row it supersedes — so this
     * prioritizes by status first (an actionable membership always outranks a
     * superseded one) and falls back to recency only within the same status.
     */
    private function resolveDisplayMembership(int $userId): ?Membership
    {
        $priority = [
            MembershipStatus::Active => 0,
            MembershipStatus::PendingPayment => 1,
            MembershipStatus::Draft => 2,
            MembershipStatus::Expired => 3,
            MembershipStatus::Cancelled => 4,
        ];

        return Membership::query()
            ->with('plan')
            ->where('user_id', $userId)
            ->get()
            ->sort(function (Membership $a, Membership $b) use ($priority) {
                $rank = ($priority[$a->status] ?? 99) <=> ($priority[$b->status] ?? 99);

                return $rank !== 0 ? $rank : $b->created_at <=> $a->created_at;
            })
            ->first();
    }
}
