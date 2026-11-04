<?php

namespace App\Http\Controllers;

use App\Exceptions\TicketOrderException;
use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Models\Event;
use App\Models\EventTicketOrder;
use App\Models\Payment;
use App\Services\Tickets\TicketAvailability;
use App\Services\Tickets\TicketConfirmationService;
use App\Services\Tickets\TicketOrderService;
use App\Services\Tickets\TicketPricingService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Public ticket purchase for paid events. All money and eligibility decisions
 * live in the services; this controller only moves data in and out and
 * enforces who may see which order.
 */
class TicketController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private readonly TicketAvailability $availability,
        private readonly TicketPricingService $pricing,
        private readonly TicketOrderService $orders,
        private readonly TicketConfirmationService $confirmations,
    ) {}

    // ── Checkout page ────────────────────────────────────────────────────────

    public function checkout(string $slug): View|RedirectResponse
    {
        $event = $this->eventBySlug($slug);
        if ($event->registration_type !== 'paid') {
            return redirect('/events/'.$event->slug);
        }

        $user = Auth::user();
        $max = (int) config('tickets.max_per_order', 10);
        $check = $this->availability->check($event, 1);
        $remaining = $check->remaining;
        $maxSelectable = $remaining === null ? $max : max(0, min($max, $remaining));

        // First-load preview for one ticket, so the page opens with the right prices.
        $preview = $this->pricing->resolve($event, 1, $user);

        return view('pages.event-tickets', [
            'title' => 'Tickets — '.$event->title,
            'description' => 'Buy tickets for '.$event->title.'.',
            'bodyClass' => 'page-no-hero',
            'event' => $event,
            'availability' => $check,
            'maxSelectable' => $maxSelectable,
            'memberPrice' => (float) $event->ticket_price_member,
            'standardPrice' => (float) $event->ticket_price_non_member,
            'saving' => max(0, (float) $event->ticket_price_non_member - (float) $event->ticket_price_member),
            'user' => $user,
            'preview' => $preview,
            'csrfToken' => csrf_token(),
            'extraStyles' => '<link rel="stylesheet" href="'.asset('css/checkout.css').'">'
                .'<link rel="stylesheet" href="'.asset('css/event-tickets.css').'">',
            'extraScripts' => '<script src="'.asset('js/event-tickets.js').'"></script>',
        ]);
    }

    /**
     * Server-side price and availability for the live order summary.
     * Display only: the order endpoint re-prices everything itself.
     */
    public function quote(Request $request, string $slug): JsonResponse
    {
        $event = $this->eventBySlug($slug);
        $max = (int) config('tickets.max_per_order', 10);
        $quantity = max(1, min($max, (int) $request->input('quantity', 1)));
        $attending = filter_var($request->input('buyerAttending', true), FILTER_VALIDATE_BOOLEAN);

        if ($event->registration_type !== 'paid' || $event->ticket_price_member === null) {
            return $this->jsonError('Tickets are not sold for this event.', 404);
        }

        $check = $this->availability->check($event, $quantity);
        $priced = $this->pricing->resolve(
            $event,
            $quantity,
            Auth::user(),
            $this->clean($request->input('membershipNumber')),
            $this->clean($request->input('membershipEmail')),
            $attending,
        );

        return $this->jsonResponse([
            'ok' => true,
            'available' => $check->ok(),
            'availabilityReason' => $check->reason,
            'availabilityMessage' => $check->message(),
            'remaining' => $check->remaining,
            'pricing' => $priced->toArray(),
            'saving' => max(0, (float) $event->ticket_price_non_member - (float) $event->ticket_price_member),
        ]);
    }

    // ── Place order ──────────────────────────────────────────────────────────

    public function order(Request $request, string $slug): JsonResponse
    {
        $event = $this->eventBySlug($slug);

        try {
            $result = $this->orders->create($event->id, [
                'buyerName' => $request->input('buyerName'),
                'buyerEmail' => $request->input('buyerEmail'),
                'buyerPhone' => $request->input('buyerPhone'),
                'payerPhone' => $request->input('payerPhone'),
                'provider' => strtolower((string) $request->input('provider')),
                'quantity' => $request->input('quantity'),
                'attendees' => $request->input('attendees', []),
                'buyerAttending' => $request->input('buyerAttending', true),
                'membershipNumber' => $this->clean($request->input('membershipNumber')),
                'membershipEmail' => $this->clean($request->input('membershipEmail')),
                'expectedTotal' => $request->input('expectedTotal'),
                'idempotencyToken' => $this->clean($request->input('idempotencyToken')),
            ], Auth::user());
        } catch (ValidationException $e) {
            return $this->jsonResponse([
                'ok' => false,
                'message' => (string) collect($e->errors())->flatten()->first(),
                'errors' => $e->errors(),
            ], 422);
        } catch (TicketOrderException $e) {
            return $this->orderFailure($e);
        } catch (Throwable $e) {
            Log::error('[TicketController] Could not place ticket order: '.$e->getMessage());

            return $this->jsonError('Could not place your order. Please try again.', 500);
        }

        $this->grantAccess($result->order);

        return $this->jsonResponse([
            'ok' => true,
            'orderNumber' => $result->order->order_number,
            'resumed' => $result->resumed,
            'paymentInstructions' => $result->paymentInstructions,
            'expiresAt' => $result->order->expires_at?->toIso8601String(),
            'redirect' => url('/tickets/'.$result->order->order_number),
        ]);
    }

    // ── Order page + status polling ──────────────────────────────────────────

    public function show(Request $request, string $orderNumber): View
    {
        $order = $this->orderForViewer($request, $orderNumber);
        $order->load(['event', 'tickets']);
        $payment = Payment::query()->where('order_number', $order->order_number)->first();

        return view('pages.ticket-order', [
            'title' => 'Your tickets — '.$order->event->title,
            'description' => 'Your ticket order for '.$order->event->title.'.',
            'bodyClass' => 'page-no-hero',
            'order' => $order,
            'event' => $order->event,
            'tickets' => $order->tickets,
            'payment' => $payment,
            'extraStyles' => '<link rel="stylesheet" href="'.asset('css/checkout.css').'">'
                .'<link rel="stylesheet" href="'.asset('css/event-tickets.css').'">',
            'extraScripts' => '<script src="'.asset('js/ticket-order.js').'"></script>',
        ]);
    }

    public function status(Request $request, string $orderNumber): JsonResponse
    {
        $order = $this->orderForViewer($request, $orderNumber);
        $gatewayDown = false;

        if ($order->status === EventTicketOrder::STATUS_PENDING_PAYMENT || $order->status === EventTicketOrder::STATUS_CANCELLED) {
            try {
                $this->orders->refreshFromGateway($order);
            } catch (Throwable $e) {
                $gatewayDown = true;
            }
            $order = $order->fresh();
        }

        return $this->jsonResponse([
            'ok' => true,
            'orderStatus' => $order->status,
            'gatewayUnavailable' => $gatewayDown,
            'expiresAt' => $order->expires_at?->toIso8601String(),
        ]);
    }

    // ── "Find my tickets" ────────────────────────────────────────────────────

    public function findForm(): View
    {
        return view('pages.tickets-find', [
            'title' => 'Find my tickets',
            'description' => 'Get your ticket confirmation emailed again.',
            'bodyClass' => 'page-no-hero',
            'extraStyles' => '<link rel="stylesheet" href="'.asset('css/checkout.css').'">'
                .'<link rel="stylesheet" href="'.asset('css/event-tickets.css').'">',
            'sent' => false,
        ]);
    }

    /**
     * Always answers identically whether or not the order exists, so the form
     * can't be used to find out which order numbers or emails are real. The
     * only effect is a resend to the email already on the order.
     */
    public function find(Request $request): View
    {
        $number = trim((string) $request->input('orderNumber', ''));
        $email = strtolower(trim((string) $request->input('email', '')));

        if ($number !== '' && $email !== '') {
            $order = EventTicketOrder::query()
                ->where('order_number', $number)
                ->where('buyer_email', $email)
                ->first();

            // One resend per order every 10 minutes, on top of the route throttle.
            if ($order !== null && Cache::add('tickets:resend:'.$order->id, 1, 600)) {
                $this->confirmations->resend($order);
            }
        }

        return view('pages.tickets-find', [
            'title' => 'Find my tickets',
            'description' => 'Get your ticket confirmation emailed again.',
            'bodyClass' => 'page-no-hero',
            'extraStyles' => '<link rel="stylesheet" href="'.asset('css/checkout.css').'">'
                .'<link rel="stylesheet" href="'.asset('css/event-tickets.css').'">',
            'sent' => true,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function eventBySlug(string $slug): Event
    {
        return Event::query()->where('slug', $slug)->firstOrFail();
    }

    /**
     * Who may see an order: the browser session that placed it, the logged-in
     * buyer, or anyone holding the signed link from the confirmation email.
     * Anyone else gets the same 404 as a non-existent order.
     */
    private function orderForViewer(Request $request, string $orderNumber): EventTicketOrder
    {
        $order = EventTicketOrder::query()->where('order_number', $orderNumber)->first();
        if ($order === null) {
            abort(404);
        }

        $allowed = (bool) session()->get("order_access.{$orderNumber}")
            || $request->hasValidSignature();

        $user = Auth::user();
        if (! $allowed && $user !== null) {
            $allowed = ($order->user_id !== null && (int) $order->user_id === (int) $user->id)
                || strtolower((string) $user->email) === strtolower((string) $order->buyer_email);
        }

        if (! $allowed) {
            abort(404);
        }

        return $order;
    }

    private function grantAccess(EventTicketOrder $order): void
    {
        // Same session key the shop uses, so /shop/checkout/verify also works for this browser.
        session()->put("order_access.{$order->order_number}", true);
    }

    private function orderFailure(TicketOrderException $e): JsonResponse
    {
        if ($e->order !== null) {
            $this->grantAccess($e->order);
        }

        return match ($e->reason) {
            TicketOrderException::UNAVAILABLE => $this->jsonResponse([
                'ok' => false,
                'message' => $e->getMessage(),
                'reason' => $e->availabilityReason,
            ], 409),
            TicketOrderException::PRICE_CHANGED => $this->jsonResponse([
                'ok' => false,
                'message' => $e->getMessage(),
                'reason' => 'price_changed',
                'pricing' => $e->pricing?->toArray(),
            ], 409),
            TicketOrderException::MEMBER_NAME_MISMATCH => $this->jsonResponse([
                'ok' => false,
                'message' => $e->getMessage(),
                'reason' => 'member_name_mismatch',
            ], 422),
            TicketOrderException::PAYMENT_UNKNOWN => $this->jsonResponse([
                'ok' => false,
                'message' => $e->getMessage(),
                'reason' => 'payment_unknown',
                'orderNumber' => $e->order?->order_number,
                'redirect' => $e->order ? url('/tickets/'.$e->order->order_number) : null,
            ], 202),
            default => $this->jsonResponse([
                'ok' => false,
                'message' => $e->getMessage(),
                'reason' => 'payment_failed',
            ], 502),
        };
    }

    private function clean(mixed $value): ?string
    {
        $string = trim((string) ($value ?? ''));

        return $string === '' ? null : $string;
    }
}
