<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MembershipPaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventTicketOrder;
use App\Models\MembershipPayment;
use App\Models\Payment;
use App\Services\AdminPermissionService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Read-only payment follow-up pages, one per area (shop, memberships, event
 * tickets). Each lists every payment attempt with its status so admins can chase
 * pending / failed ones. All three render the shared admin.payments.index view.
 */
class PaymentsController extends Controller
{
    private const PAGE_SIZE = 50;

    /** status => [label, pill colour] */
    private const SHOP_STATUSES = [
        'completed' => ['Completed', 'green'],
        'pending' => ['Pending', 'orange'],
        'failed' => ['Failed', 'red'],
        'cancelled' => ['Cancelled', 'muted'],
        'refunded' => ['Refunded', 'blue'],
    ];

    private const MEMBER_STATUSES = [
        MembershipPaymentStatus::Paid => ['Completed', 'green'],
        MembershipPaymentStatus::PartiallyPaid => ['Partially paid', 'orange'],
        MembershipPaymentStatus::Pending => ['Pending', 'orange'],
        MembershipPaymentStatus::Failed => ['Failed', 'red'],
        MembershipPaymentStatus::Refunded => ['Refunded', 'blue'],
    ];

    private const TICKET_STATUSES = [
        EventTicketOrder::STATUS_PAID => ['Completed', 'green'],
        EventTicketOrder::STATUS_PENDING_PAYMENT => ['Pending', 'orange'],
        EventTicketOrder::STATUS_PAYMENT_REVIEW_REQUIRED => ['Needs review', 'red'],
        EventTicketOrder::STATUS_FAILED => ['Failed', 'red'],
        EventTicketOrder::STATUS_CANCELLED => ['Cancelled / expired', 'muted'],
    ];

    public function __construct(private readonly AdminPermissionService $permissions) {}

    public function shop(Request $request): View
    {
        $f = $this->filters($request, self::SHOP_STATUSES);
        $base = Payment::query();
        $this->applyDates($base, 'created_at', $f);
        $this->applySearch($base, $f['q'], ['order_number', 'customer_name', 'customer_email', 'customer_phone', 'lenco_reference']);

        $counts = $this->counts(clone $base, 'status');
        $sums = $this->sums(clone $base, 'status', 'amount');

        $page = (clone $base)->with('order:id,order_number')
            ->when($f['status'] !== '', fn ($q) => $q->where('status', $f['status']))
            ->orderByDesc('created_at')->paginate(self::PAGE_SIZE)->withQueryString();

        $rows = $page->getCollection()->map(fn (Payment $p) => [
            'ref' => $p->order_number,
            'link' => $p->order ? '/admin/orders/'.$p->order->id : null,
            'who' => $p->customer_name,
            'email' => $p->customer_email,
            'phone' => $p->customer_phone,
            'detail' => trim(($p->payment_method ?? '').($p->lenco_provider ? ' · '.$p->lenco_provider : ''), ' ·'),
            'amount' => (float) $p->amount,
            'currency' => $p->currency ?: 'ZMW',
            'status' => $p->status,
            'date' => $p->completed_at ?? $p->failed_at ?? $p->created_at,
            'note' => $p->failure_reason,
        ]);

        return $this->render('Shop payments', 'shop-payments', 'Orders', '/admin/orders', '/admin/orders/payments',
            self::SHOP_STATUSES, $counts, $sums, $f, $page, $rows, 'Order #');
    }

    public function members(Request $request): View
    {
        $f = $this->filters($request, self::MEMBER_STATUSES);
        $scope = $this->permissions->memberSatelliteScope($this->permissions->currentAdmin());

        $base = MembershipPayment::query()
            ->when($scope !== null, fn ($q) => $q->whereHas('membership.user', fn ($u) => $u->whereIn('satellite_id', $scope)));
        $this->applyDates($base, 'created_at', $f);
        if ($f['q'] !== '') {
            $like = '%'.addcslashes($f['q'], '\\%_').'%';
            $base->where(function (Builder $w) use ($like): void {
                $w->where('receipt_number', 'like', $like)
                    ->orWhere('payment_reference', 'like', $like)
                    ->orWhere('lenco_reference', 'like', $like)
                    ->orWhereHas('membership', fn ($m) => $m->where('membership_number', 'like', $like))
                    ->orWhereHas('membership.user', fn ($u) => $u->where('last_name', 'like', $like)
                        ->orWhere('other_names', 'like', $like)->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like));
            });
        }

        $counts = $this->counts(clone $base, 'status');
        $sums = $this->sums(clone $base, 'status', 'amount_paid');

        $page = (clone $base)->with('membership.user')
            ->when($f['status'] !== '', fn ($q) => $q->where('status', $f['status']))
            ->orderByDesc('created_at')->paginate(self::PAGE_SIZE)->withQueryString();

        $rows = $page->getCollection()->map(function (MembershipPayment $p) {
            $user = $p->membership?->user;
            $due = (float) $p->amount;
            $paid = (float) $p->amount_paid;
            $note = in_array($p->status, [MembershipPaymentStatus::PartiallyPaid, MembershipPaymentStatus::Pending], true) && $due > $paid
                ? 'Paid K'.number_format($paid, 2).' of K'.number_format($due, 2).' · balance K'.number_format($due - $paid, 2)
                : null;

            return [
                'ref' => $p->receipt_number ?: ($p->payment_reference ?: '—'),
                'link' => $user ? '/admin/members/'.$user->id : null,
                'who' => $user?->name ?? '—',
                'email' => $user?->email,
                'phone' => $user?->phone,
                'detail' => $p->membership?->membership_number ? 'Member #'.$p->membership->membership_number : '',
                'amount' => $due,
                'currency' => $p->currency ?: 'ZMW',
                'status' => $p->status,
                'date' => $p->paid_at ?? $p->created_at,
                'note' => $note,
            ];
        });

        return $this->render('Membership payments', 'member-payments', 'Members', '/admin/members', '/admin/members/payments',
            self::MEMBER_STATUSES, $counts, $sums, $f, $page, $rows, 'Receipt / ref');
    }

    public function tickets(Request $request): View
    {
        $f = $this->filters($request, self::TICKET_STATUSES);
        $eventId = trim((string) $request->query('event', ''));

        $base = EventTicketOrder::query()->when($eventId !== '', fn ($q) => $q->where('event_id', $eventId));
        $this->applyDates($base, 'created_at', $f);
        $this->applySearch($base, $f['q'], ['order_number', 'buyer_name', 'buyer_email', 'buyer_phone', 'payer_phone']);

        $counts = $this->counts(clone $base, 'status');
        $sums = $this->sums(clone $base, 'status', 'total');

        $page = (clone $base)->with('event:id,title')
            ->when($f['status'] !== '', fn ($q) => $q->where('status', $f['status']))
            ->orderByDesc('created_at')->paginate(self::PAGE_SIZE)->withQueryString();

        $rows = $page->getCollection()->map(fn (EventTicketOrder $o) => [
            'ref' => $o->order_number,
            'link' => '/admin/events/'.$o->event_id.'/tickets?status=all&q='.urlencode($o->order_number),
            'who' => $o->buyer_name,
            'email' => $o->buyer_email,
            'phone' => $o->payer_phone ?: $o->buyer_phone,
            'detail' => ($o->event?->title ?? 'Event').' · '.$o->quantity.' ticket(s)',
            'amount' => (float) $o->total,
            'currency' => 'ZMW',
            'status' => $o->status,
            'date' => $o->paid_at ?? $o->created_at,
            'note' => $o->status === EventTicketOrder::STATUS_PAYMENT_REVIEW_REQUIRED ? ($o->review_reason ? str_replace('_', ' ', $o->review_reason) : null) : null,
        ]);

        $extra = [
            'name' => 'event',
            'label' => 'Event',
            'value' => $eventId,
            'options' => Event::query()->where('registration_type', 'paid')->orderByDesc('event_date')->pluck('title', 'id')->all(),
        ];

        return $this->render('Ticket payments', 'ticket-payments', 'Events', '/admin/events/list', '/admin/events/payments',
            self::TICKET_STATUSES, $counts, $sums, $f, $page, $rows, 'Order #', $extra);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param array<string, array{0:string,1:string}> $statuses */
    private function filters(Request $request, array $statuses): array
    {
        $status = (string) $request->query('status', '');
        $date = fn (string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($k)) ? (string) $request->query($k) : '';

        return [
            'status' => array_key_exists($status, $statuses) ? $status : '',
            'q' => trim((string) $request->query('q', '')),
            'from' => $date('from'),
            'to' => $date('to'),
        ];
    }

    private function applyDates(Builder $q, string $col, array $f): void
    {
        $q->when($f['from'] !== '', fn ($b) => $b->where($col, '>=', $f['from'].' 00:00:00'))
            ->when($f['to'] !== '', fn ($b) => $b->where($col, '<=', $f['to'].' 23:59:59'));
    }

    /** @param list<string> $cols */
    private function applySearch(Builder $q, string $term, array $cols): void
    {
        if ($term === '') {
            return;
        }
        $like = '%'.addcslashes($term, '\\%_').'%';
        $q->where(function (Builder $w) use ($cols, $like): void {
            foreach ($cols as $c) {
                $w->orWhere($c, 'like', $like);
            }
        });
    }

    /** @return array<string,int> */
    private function counts(Builder $q, string $col): array
    {
        return $q->selectRaw("$col as k, count(*) as n")->groupBy($col)->pluck('n', 'k')->map(fn ($n) => (int) $n)->all();
    }

    /** @return array<string,float> */
    private function sums(Builder $q, string $col, string $amount): array
    {
        return $q->selectRaw("$col as k, sum($amount) as s")->groupBy($col)->pluck('s', 'k')->map(fn ($n) => (float) $n)->all();
    }

    private function render(string $title, string $active, string $parentLabel, string $parentUrl, string $selfUrl,
        array $statuses, array $counts, array $sums, array $f, $page, $rows, string $refLabel, ?array $extra = null): View
    {
        return view('admin.payments.index', [
            'pageTitle' => $title,
            'activePage' => $active,
            'breadcrumbs' => [
                ['label' => 'Admin', 'url' => '/admin/dashboard'],
                ['label' => $parentLabel, 'url' => $parentUrl],
                ['label' => $title],
            ],
            'statuses' => $statuses,
            'counts' => $counts,
            'sums' => $sums,
            'filters' => $f,
            'page' => $page,
            'rows' => $rows,
            'refLabel' => $refLabel,
            'selfUrl' => $selfUrl,
            'extraFilter' => $extra,
        ]);
    }
}
