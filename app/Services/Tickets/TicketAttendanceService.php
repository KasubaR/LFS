<?php

namespace App\Services\Tickets;

use App\Models\Event;
use App\Models\EventTicket;
use App\Models\EventTicketAudit;
use App\Models\EventTicketOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Manual attendance at the entrance. No scanning: organisers search for the
 * customer, confirm their email and ticket count, and mark each attendee
 * present.
 *
 * Marking is one conditional UPDATE, so two organisers tapping the same
 * attendee at once admit exactly one person, and the number admitted can
 * never exceed the tickets purchased (it is per attendee).
 */
class TicketAttendanceService
{
    public const MARKED = 'marked';

    public const ALREADY_PRESENT = 'already_present';

    public const NOT_PAYABLE = 'not_payable';

    public const NOT_FOUND = 'not_found';

    public const UNDONE = 'undone';

    public const NOT_PRESENT = 'not_present';

    public const MIN_SEARCH_LENGTH = 2;

    /**
     * Finds orders by attendee name, buyer name, order number, buyer or
     * attendee email, or ticket reference. Paid orders first; unpaid orders
     * are returned too so staff can see why someone cannot be admitted.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function search(Event $event, string $query, int $limit = 25): Collection
    {
        $query = trim($query);
        if (mb_strlen($query) < self::MIN_SEARCH_LENGTH) {
            return collect();
        }

        $like = '%'.addcslashes($query, '\\%_').'%';

        return EventTicketOrder::query()
            ->with('tickets')
            ->where('event_id', $event->id)
            ->where(function ($w) use ($like): void {
                $w->where('order_number', 'like', $like)
                    ->orWhere('buyer_name', 'like', $like)
                    ->orWhere('buyer_email', 'like', $like)
                    ->orWhereHas('tickets', function ($t) use ($like): void {
                        $t->where('attendee_name', 'like', $like)
                            ->orWhere('attendee_email', 'like', $like)
                            ->orWhere('ticket_reference', 'like', $like);
                    });
            })
            ->orderByRaw("CASE status WHEN 'paid' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (EventTicketOrder $o): array => $this->orderPayload($o));
    }

    /**
     * @return array{result: string, ticket: array<string, mixed>|null, presentAt: string|null}
     */
    public function markPresent(Event $event, string $ticketId, ?string $adminId, ?string $adminName): array
    {
        return DB::transaction(function () use ($event, $ticketId, $adminId, $adminName): array {
            $ticket = EventTicket::query()->where('event_id', $event->id)->whereKey($ticketId)->first();
            if ($ticket === null) {
                return ['result' => self::NOT_FOUND, 'ticket' => null, 'presentAt' => null];
            }

            // One conditional UPDATE: only an unmarked ticket on a PAID order is admitted.
            $updated = EventTicket::query()
                ->whereKey($ticketId)
                ->where('event_id', $event->id)
                ->whereNull('checked_in_at')
                ->whereIn('order_id', EventTicketOrder::query()->select('id')->where('status', EventTicketOrder::STATUS_PAID))
                ->update(['checked_in_at' => now(), 'updated_at' => now()]);

            $ticket = $ticket->fresh(['order']);

            if ($updated === 1) {
                $this->audit($event, $ticket, EventTicketAudit::ACTION_MARK_PRESENT, $adminId, $adminName, null, [
                    'attendee' => $ticket->attendee_name,
                    'reference' => $ticket->ticket_reference,
                ]);

                return ['result' => self::MARKED, 'ticket' => $this->ticketPayload($ticket), 'presentAt' => $ticket->checked_in_at?->toIso8601String()];
            }

            if ($ticket->checked_in_at !== null) {
                return ['result' => self::ALREADY_PRESENT, 'ticket' => $this->ticketPayload($ticket), 'presentAt' => $ticket->checked_in_at->toIso8601String()];
            }

            return ['result' => self::NOT_PAYABLE, 'ticket' => $this->ticketPayload($ticket), 'presentAt' => null];
        });
    }

    /**
     * Admin-only correction of a mistaken "present". Always audited with a reason.
     *
     * @return array{result: string, ticket: array<string, mixed>|null}
     */
    public function undoPresent(Event $event, string $ticketId, ?string $adminId, ?string $adminName, string $reason): array
    {
        return DB::transaction(function () use ($event, $ticketId, $adminId, $adminName, $reason): array {
            $ticket = EventTicket::query()->where('event_id', $event->id)->whereKey($ticketId)->first();
            if ($ticket === null) {
                return ['result' => self::NOT_FOUND, 'ticket' => null];
            }

            $wasAt = $ticket->checked_in_at;

            $updated = EventTicket::query()
                ->whereKey($ticketId)
                ->whereNotNull('checked_in_at')
                ->update(['checked_in_at' => null, 'updated_at' => now()]);

            $ticket = $ticket->fresh(['order']);

            if ($updated !== 1) {
                return ['result' => self::NOT_PRESENT, 'ticket' => $this->ticketPayload($ticket)];
            }

            $this->audit($event, $ticket, EventTicketAudit::ACTION_UNDO_PRESENT, $adminId, $adminName, $reason, [
                'attendee' => $ticket->attendee_name,
                'reference' => $ticket->ticket_reference,
                'wasPresentAt' => $wasAt?->toIso8601String(),
            ]);

            return ['result' => self::UNDONE, 'ticket' => $this->ticketPayload($ticket)];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function orderPayload(EventTicketOrder $order): array
    {
        $order->loadMissing('tickets');
        $tickets = $order->tickets->map(fn (EventTicket $t): array => $this->ticketPayload($t, $order))->values()->all();

        return [
            'id' => $order->id,
            'orderNumber' => $order->order_number,
            'status' => $order->status,
            'reviewReason' => $order->review_reason,
            'canAdmit' => $order->status === EventTicketOrder::STATUS_PAID,
            'buyerName' => $order->buyer_name,
            'buyerEmail' => $order->buyer_email,
            'quantity' => (int) $order->quantity,
            'presentCount' => collect($tickets)->where('present', true)->count(),
            'total' => (float) $order->total,
            'tickets' => $tickets,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function ticketPayload(EventTicket $ticket, ?EventTicketOrder $order = null): array
    {
        $order ??= $ticket->order;

        return [
            'id' => $ticket->id,
            'position' => (int) $ticket->position,
            'name' => $ticket->attendee_name,
            'email' => $ticket->attendee_email,
            'reference' => $ticket->ticket_reference,
            'isMemberTicket' => (bool) $ticket->is_member_discount,
            'membershipNumber' => $ticket->membership_number,
            'present' => $ticket->checked_in_at !== null,
            'presentAt' => $ticket->checked_in_at?->toIso8601String(),
            'canMark' => $order !== null && $order->status === EventTicketOrder::STATUS_PAID && $ticket->checked_in_at === null,
            'orderStatus' => $order?->status,
        ];
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function audit(Event $event, EventTicket $ticket, string $action, ?string $adminId, ?string $adminName, ?string $reason, array $details): void
    {
        EventTicketAudit::query()->create([
            'event_id' => $event->id,
            'order_id' => $ticket->order_id,
            'ticket_id' => $ticket->id,
            'admin_id' => $adminId,
            'admin_name' => $adminName,
            'action' => $action,
            'reason' => $reason,
            'details' => $details,
        ]);
    }
}
