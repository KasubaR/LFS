<?php

namespace App\Services\Tickets;

use App\Models\Event;
use App\Models\EventTicket;
use App\Models\EventTicketOrder;
use Illuminate\Support\Collection;

/**
 * Sales and attendance figures for the admin ticket page, and the CSV export.
 * Counts by ATTENDEE (who is coming) and by BUYER (who paid) are kept apart.
 */
class TicketReportService
{
    public function __construct(private readonly TicketAvailability $availability) {}

    /**
     * @return array<string, mixed>
     */
    public function stats(Event $event): array
    {
        $paid = fn () => EventTicketOrder::query()->where('event_id', $event->id)->where('status', EventTicketOrder::STATUS_PAID);

        $paidTickets = EventTicket::query()
            ->where('event_id', $event->id)
            ->whereIn('order_id', $paid()->select('id'));

        $sold = (int) $paid()->sum('quantity');
        $present = (int) (clone $paidTickets)->whereNotNull('checked_in_at')->count();
        $memberTickets = (int) (clone $paidTickets)->where('is_member_discount', true)->count();

        $livePending = EventTicketOrder::query()
            ->where('event_id', $event->id)
            ->where('status', EventTicketOrder::STATUS_PENDING_PAYMENT)
            ->where('expires_at', '>', now());

        return [
            'capacity' => $event->ticket_capacity,
            'remaining' => $this->availability->remainingCapacity($event),
            'ticketsSold' => $sold,
            'buyers' => (int) $paid()->count(),
            'revenue' => (float) $paid()->sum('total'),
            'memberTickets' => $memberTickets,
            'standardTickets' => max(0, $sold - $memberTickets),
            'present' => $present,
            'notYetPresent' => max(0, $sold - $present),
            'pendingOrders' => (int) (clone $livePending)->count(),
            'pendingTickets' => (int) (clone $livePending)->sum('quantity'),
            'reviewOrders' => (int) EventTicketOrder::query()
                ->where('event_id', $event->id)
                ->where('status', EventTicketOrder::STATUS_PAYMENT_REVIEW_REQUIRED)->count(),
        ];
    }

    /**
     * Orders held for payment review, oldest first (money received, nothing confirmed).
     *
     * @return Collection<int, EventTicketOrder>
     */
    public function reviewOrders(Event $event): Collection
    {
        return EventTicketOrder::query()
            ->where('event_id', $event->id)
            ->where('status', EventTicketOrder::STATUS_PAYMENT_REVIEW_REQUIRED)
            ->orderBy('updated_at')
            ->get();
    }

    /**
     * One row per ATTENDEE, ready for fputcsv().
     *
     * @param  list<string>  $statuses
     * @return list<list<string>>
     */
    public function exportRows(Event $event, array $statuses): array
    {
        $rows = [[
            'Order number', 'Order status', 'Buyer name', 'Buyer email', 'Buyer phone', 'Payer phone',
            'Ticket reference', 'Attendee name', 'Attendee email', 'Price (ZMW)',
            'Member ticket', 'Membership number', 'Present', 'Present at', 'Paid at',
        ]];

        EventTicketOrder::query()
            ->with('tickets')
            ->where('event_id', $event->id)
            ->whereIn('status', $statuses)
            ->orderBy('created_at')
            ->get()
            ->each(function (EventTicketOrder $order) use (&$rows): void {
                foreach ($order->tickets as $t) {
                    $rows[] = array_map([$this, 'safeCell'], [
                        $order->order_number,
                        $order->status,
                        $order->buyer_name,
                        $order->buyer_email,
                        $order->buyer_phone,
                        $order->payer_phone,
                        $t->ticket_reference,
                        $t->attendee_name,
                        (string) $t->attendee_email,
                        number_format((float) $t->price, 2, '.', ''),
                        $t->is_member_discount ? 'Yes' : 'No',
                        (string) $t->membership_number,
                        $t->checked_in_at ? 'Yes' : 'No',
                        $t->checked_in_at?->format('Y-m-d H:i') ?? '',
                        $order->paid_at?->format('Y-m-d H:i') ?? '',
                    ]);
                }
            });

        return $rows;
    }

    /**
     * Stops spreadsheet formula injection: a buyer-typed value that starts
     * with = + - @ (or a tab/CR) would otherwise run as a formula in Excel.
     */
    public function safeCell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
