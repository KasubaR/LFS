<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventTicketOrder;
use App\Services\AdminPermissionService;
use App\Services\Tickets\TicketAttendanceService;
use App\Services\Tickets\TicketReportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin side of event tickets: who bought what, and who has arrived.
 *
 * Routes sit inside the events group, so viewing needs events:read and every
 * POST needs events:write (read-only auditors get a 403 from the middleware).
 */
class EventTicketController extends Controller
{
    use RespondsWithJson;

    private const STATUS_FILTERS = [
        'paid' => 'Paid',
        'all' => 'All',
        EventTicketOrder::STATUS_PENDING_PAYMENT => 'Awaiting payment',
        EventTicketOrder::STATUS_PAYMENT_REVIEW_REQUIRED => 'Payment review',
        EventTicketOrder::STATUS_CANCELLED => 'Cancelled / expired',
        EventTicketOrder::STATUS_FAILED => 'Failed',
    ];

    private const PAGE_SIZE = 50;

    public function __construct(
        private readonly TicketAttendanceService $attendance,
        private readonly TicketReportService $reports,
        private readonly AdminPermissionService $permissions,
    ) {}

    // ── Pages ────────────────────────────────────────────────────────────────

    public function index(Request $request, string $id): View|RedirectResponse
    {
        $event = $this->paidEvent($id);
        if ($event === null) {
            return redirect('/admin/events/list');
        }

        $status = (string) $request->query('status', 'paid');
        if (! array_key_exists($status, self::STATUS_FILTERS)) {
            $status = 'paid';
        }
        $search = trim((string) $request->query('q', ''));

        $orders = EventTicketOrder::query()
            ->with('tickets')
            ->where('event_id', $event->id)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($search !== '', function ($q) use ($search): void {
                $like = '%'.addcslashes($search, '\\%_').'%';
                $q->where(function ($w) use ($like): void {
                    $w->where('order_number', 'like', $like)
                        ->orWhere('buyer_name', 'like', $like)
                        ->orWhere('buyer_email', 'like', $like)
                        ->orWhereHas('tickets', fn ($t) => $t->where('attendee_name', 'like', $like)->orWhere('ticket_reference', 'like', $like));
                });
            })
            ->orderByDesc('created_at')
            ->paginate(self::PAGE_SIZE)
            ->withQueryString();

        return view('admin.events.tickets', [
            'pageTitle' => 'Tickets — '.$event->title,
            'activePage' => 'events',
            'breadcrumbs' => [
                ['label' => 'Admin', 'url' => '/admin'],
                ['label' => 'Events', 'url' => '/admin/events/list'],
                ['label' => $event->title, 'url' => '/admin/events/'.$event->id.'/edit'],
                ['label' => 'Tickets'],
            ],
            'event' => $event,
            'stats' => $this->reports->stats($event),
            'reviewOrders' => $this->reports->reviewOrders($event),
            'orders' => $orders,
            'statusFilters' => self::STATUS_FILTERS,
            'filterStatus' => $status,
            'filterQuery' => $search,
        ]);
    }

    public function attendancePage(string $id): View|RedirectResponse
    {
        $event = $this->paidEvent($id);
        if ($event === null) {
            return redirect('/admin/events/list');
        }

        $stats = $this->reports->stats($event);

        return view('admin.events.attendance', [
            'pageTitle' => 'Entrance — '.$event->title,
            'activePage' => 'events',
            'breadcrumbs' => [
                ['label' => 'Admin', 'url' => '/admin'],
                ['label' => 'Events', 'url' => '/admin/events/list'],
                ['label' => $event->title, 'url' => '/admin/events/'.$event->id.'/tickets'],
                ['label' => 'Entrance'],
            ],
            'event' => $event,
            'stats' => $stats,
            'minSearch' => TicketAttendanceService::MIN_SEARCH_LENGTH,
            'extraScripts' => '<script src="/admin/js/ticket-attendance.js"></script>',
        ]);
    }

    // ── Attendance actions (JSON) ────────────────────────────────────────────

    public function search(Request $request, string $id): JsonResponse
    {
        $event = $this->paidEvent($id);
        if ($event === null) {
            return $this->jsonError('Event not found.', 404);
        }

        return $this->jsonResponse([
            'ok' => true,
            'results' => $this->attendance->search($event, (string) $request->query('q', ''))->values()->all(),
            'stats' => $this->reports->stats($event),
        ]);
    }

    public function present(Request $request, string $id, string $ticketId): JsonResponse
    {
        $event = $this->paidEvent($id);
        if ($event === null) {
            return $this->jsonError('Event not found.', 404);
        }

        $admin = $this->permissions->currentAdmin($request);
        $outcome = $this->attendance->markPresent($event, $ticketId, $admin?->id !== null ? (string) $admin->id : null, $admin?->name);

        return match ($outcome['result']) {
            TicketAttendanceService::MARKED => $this->jsonResponse([
                'ok' => true,
                'result' => 'marked',
                'ticket' => $outcome['ticket'],
                'stats' => $this->reports->stats($event),
            ]),
            TicketAttendanceService::ALREADY_PRESENT => $this->jsonResponse([
                'ok' => false,
                'result' => 'already_present',
                'message' => 'Already marked present at '.$this->clock($outcome['presentAt']).'.',
                'ticket' => $outcome['ticket'],
            ], 409),
            TicketAttendanceService::NOT_PAYABLE => $this->jsonResponse([
                'ok' => false,
                'result' => 'not_payable',
                'message' => 'This order is not paid, so nobody can be admitted on it.',
                'ticket' => $outcome['ticket'],
            ], 422),
            default => $this->jsonError('Ticket not found.', 404),
        };
    }

    public function undo(Request $request, string $id, string $ticketId): JsonResponse
    {
        $event = $this->paidEvent($id);
        if ($event === null) {
            return $this->jsonError('Event not found.', 404);
        }

        $reason = trim((string) $request->input('reason', ''));
        if (mb_strlen($reason) < 3) {
            return $this->jsonError('Please give a reason for undoing this.', 422);
        }

        $admin = $this->permissions->currentAdmin($request);
        $outcome = $this->attendance->undoPresent($event, $ticketId, $admin?->id !== null ? (string) $admin->id : null, $admin?->name, mb_substr($reason, 0, 500));

        return match ($outcome['result']) {
            TicketAttendanceService::UNDONE => $this->jsonResponse([
                'ok' => true,
                'result' => 'undone',
                'ticket' => $outcome['ticket'],
                'stats' => $this->reports->stats($event),
            ]),
            TicketAttendanceService::NOT_PRESENT => $this->jsonResponse([
                'ok' => false,
                'result' => 'not_present',
                'message' => 'This attendee is not marked present.',
                'ticket' => $outcome['ticket'],
            ], 409),
            default => $this->jsonError('Ticket not found.', 404),
        };
    }

    // ── Export ───────────────────────────────────────────────────────────────

    public function export(Request $request, string $id): StreamedResponse|RedirectResponse
    {
        $event = $this->paidEvent($id);
        if ($event === null) {
            return redirect('/admin/events/list');
        }

        $statuses = $request->query('status') === 'all'
            ? [
                EventTicketOrder::STATUS_PAID,
                EventTicketOrder::STATUS_PENDING_PAYMENT,
                EventTicketOrder::STATUS_PAYMENT_REVIEW_REQUIRED,
                EventTicketOrder::STATUS_CANCELLED,
                EventTicketOrder::STATUS_FAILED,
            ]
            : [EventTicketOrder::STATUS_PAID];

        $rows = $this->reports->exportRows($event, $statuses);
        $filename = 'tickets-'.preg_replace('/[^a-z0-9]+/i', '-', strtolower($event->slug ?: $event->title)).'-'.now()->format('Ymd-Hi').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads names correctly
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function paidEvent(string $id): ?Event
    {
        $event = Event::query()->find($id);

        return $event !== null && $event->registration_type === 'paid' ? $event : null;
    }

    private function clock(?string $iso): string
    {
        return $iso === null ? 'an earlier time' : \Illuminate\Support\Carbon::parse($iso)->format('H:i');
    }
}
