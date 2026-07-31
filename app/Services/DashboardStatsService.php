<?php

namespace App\Services;

use App\Enums\MembershipHistoryEvent;
use App\Enums\MembershipPaymentStatus;
use App\Enums\MembershipStatus;
use App\Models\ContactMessage;
use App\Models\Media;
use App\Models\Membership;
use App\Models\MembershipHistory;
use App\Models\MembershipPayment;
use App\Models\Payment;
use Throwable;

class DashboardStatsService
{
    public function __construct(
        private readonly OrderService $orderService,
    ) {}

    /**
     * @return array{
     *     newMessages: int,
     *     pendingOrders: int,
     *     totalMembers: int,
     *     activeMembers: int,
     *     newMembersThisMonth: int,
     *     shopRevenue: float,
     *     membershipRevenue: float,
     *     pendingMemberships: int,
     *     failedMembershipPayments: int,
     * }
     */
    public function compute(): array
    {
        $stats = [
            'newMessages' => 0,
            'pendingOrders' => 0,
            'totalMembers' => 0,
            'activeMembers' => 0,
            'newMembersThisMonth' => 0,
            'shopRevenue' => 0.0,
            'membershipRevenue' => 0.0,
            'pendingMemberships' => 0,
            'failedMembershipPayments' => 0,
        ];

        try {
            $stats['newMessages'] = ContactMessage::query()->where('status', 'New')->count();
            $stats['pendingOrders'] = $this->orderService->countByStatus('pending_payment')
                + $this->orderService->countByStatus('paid');
            $stats['totalMembers'] = Membership::query()->distinct('user_id')->count('user_id');
            $stats['activeMembers'] = Membership::query()->where('status', MembershipStatus::Active)->count();
            $stats['newMembersThisMonth'] = Membership::query()
                ->where('created_at', '>=', now()->startOfMonth())
                ->count();
            $stats['shopRevenue'] = (float) Payment::query()
                ->where('status', 'completed')
                ->where('completed_at', '>=', now()->startOfMonth())
                ->sum('amount');
            $stats['membershipRevenue'] = (float) MembershipPayment::query()
                ->where('status', 'paid')
                ->where('paid_at', '>=', now()->startOfMonth())
                ->sum('amount_paid');
            $stats['pendingMemberships'] = Membership::query()
                ->whereIn('status', [MembershipStatus::Draft, MembershipStatus::PendingPayment])
                ->count();
            $stats['failedMembershipPayments'] = MembershipPayment::query()
                ->where('status', MembershipPaymentStatus::Failed)
                ->where('created_at', '>=', now()->subDays(7))
                ->count();
        } catch (Throwable) {
        }

        return $stats;
    }

    /**
     * @return list<array{type: string, icon: string, message: string}>
     */
    public function computeSystemAlerts(): array
    {
        return array_merge(
            $this->alertFailedMembershipPayments(),
            $this->alertFailedShopPayments(),
            $this->alertStuckPendingPayments(),
            $this->alertMembershipsExpiringSoon(),
        );
    }

    /**
     * @return list<array{type: string, icon: string, message: string}>
     */
    private function alertFailedMembershipPayments(): array
    {
        try {
            $failedPayments = MembershipPayment::query()
                ->where('status', MembershipPaymentStatus::Failed)
                ->where('created_at', '>=', now()->subDays(7))
                ->count();

            if ($failedPayments > 0) {
                return [[
                    'type' => 'warning',
                    'icon' => 'fas fa-triangle-exclamation',
                    'message' => $failedPayments === 1
                        ? '1 membership payment failed in the last 7 days.'
                        : "{$failedPayments} membership payments failed in the last 7 days.",
                ]];
            }
        } catch (Throwable) {
        }

        return [];
    }

    /**
     * @return list<array{type: string, icon: string, message: string}>
     */
    private function alertFailedShopPayments(): array
    {
        try {
            $failedPayments = Payment::query()
                ->where('status', 'failed')
                ->where('created_at', '>=', now()->subDays(7))
                ->count();

            if ($failedPayments > 0) {
                return [[
                    'type' => 'warning',
                    'icon' => 'fas fa-triangle-exclamation',
                    'message' => $failedPayments === 1
                        ? '1 shop payment failed in the last 7 days.'
                        : "{$failedPayments} shop payments failed in the last 7 days.",
                ]];
            }
        } catch (Throwable) {
        }

        return [];
    }

    /**
     * Flags payments still 'pending' well past the 5-minute cycle that
     * payments:poll-pending uses to auto-resolve them — a sign the
     * scheduler cron or the gateway integration may be stuck.
     *
     * @return list<array{type: string, icon: string, message: string}>
     */
    private function alertStuckPendingPayments(): array
    {
        try {
            $cutoff = now()->subMinutes(30);

            $staleShopPayments = Payment::query()
                ->where('status', 'pending')
                ->where('created_at', '<', $cutoff)
                ->count();

            $staleMembershipPayments = MembershipPayment::query()
                ->where('status', MembershipPaymentStatus::Pending)
                ->where('created_at', '<', $cutoff)
                ->count();

            $total = $staleShopPayments + $staleMembershipPayments;

            if ($total > 0) {
                return [[
                    'type' => 'error',
                    'icon' => 'fas fa-circle-exclamation',
                    'message' => sprintf(
                        '%d payment(s) (%d shop, %d membership) have been pending for over 30 minutes — check that the payment scheduler is running.',
                        $total,
                        $staleShopPayments,
                        $staleMembershipPayments,
                    ),
                ]];
            }
        } catch (Throwable) {
        }

        return [];
    }

    /**
     * @return list<array{type: string, icon: string, message: string}>
     */
    private function alertMembershipsExpiringSoon(): array
    {
        try {
            $expiringSoon = Membership::query()
                ->where('status', MembershipStatus::Active)
                ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays(7)->toDateString()])
                ->count();

            if ($expiringSoon > 0) {
                return [[
                    'type' => 'info',
                    'icon' => 'fas fa-hourglass-half',
                    'message' => $expiringSoon === 1
                        ? '1 membership expires in the next 7 days.'
                        : "{$expiringSoon} memberships expire in the next 7 days.",
                ]];
            }
        } catch (Throwable) {
        }

        return [];
    }

    /**
     * Monthly shop sales revenue and gallery upload counts for the "Sales
     * Performance" dashboard chart, oldest to newest, ending this month.
     *
     * @return array{
     *     sales: array{labels: list<string>, data: list<float>},
     *     gallery: array{data: list<int>},
     * }
     */
    public function computeSalesChart(int $months = 6): array
    {
        $labels = [];
        $salesData = [];
        $galleryData = [];

        try {
            for ($i = $months - 1; $i >= 0; $i--) {
                $monthStart = now()->subMonthsNoOverflow($i)->startOfMonth();
                $monthEnd = $monthStart->copy()->endOfMonth();

                $labels[] = $monthStart->format('M');

                $salesData[] = (float) Payment::query()
                    ->where('status', 'completed')
                    ->whereBetween('completed_at', [$monthStart, $monthEnd])
                    ->sum('amount');

                $galleryData[] = Media::query()
                    ->whereBetween('created_at', [$monthStart, $monthEnd])
                    ->count();
            }
        } catch (Throwable) {
            return [
                'sales' => ['labels' => [], 'data' => []],
                'gallery' => ['data' => []],
            ];
        }

        return [
            'sales' => ['labels' => $labels, 'data' => $salesData],
            'gallery' => ['data' => $galleryData],
        ];
    }

    /**
     * Membership analytics for the dashboard's analytics charts row.
     *
     * @return array{
     *     statusBreakdown: array{labels: list<string>, data: list<int>},
     *     revenue: array{labels: list<string>, shop: list<float>, membership: list<float>},
     *     growth: array{labels: list<string>, data: list<int>},
     *     registrations: array{labels: list<string>, data: list<int>},
     *     renewals: array{labels: list<string>, data: list<int>},
     *     satellites: array{labels: list<string>, data: list<int>},
     *     gender: array{labels: list<string>, data: list<int>},
     * }
     */
    public function computeMemberCharts(int $months = 6): array
    {
        return [
            'statusBreakdown' => $this->chartActiveVsExpired(),
            'revenue' => $this->chartRevenueByMonth($months),
            'growth' => $this->chartMembershipGrowth($months),
            'registrations' => $this->chartNewRegistrations($months),
            'renewals' => $this->chartRenewals($months),
            'satellites' => $this->chartMembersPerSatellite(),
            'gender' => $this->chartGenderDistribution(),
        ];
    }

    /**
     * @return array{labels: list<string>, data: list<int>}
     */
    private function chartActiveVsExpired(): array
    {
        try {
            $active = Membership::query()->where('status', MembershipStatus::Active)->count();
            $expired = Membership::query()->where('status', MembershipStatus::Expired)->count();

            return ['labels' => ['Active', 'Expired'], 'data' => [$active, $expired]];
        } catch (Throwable) {
        }

        return ['labels' => [], 'data' => []];
    }

    /**
     * @return array{labels: list<string>, shop: list<float>, membership: list<float>}
     */
    private function chartRevenueByMonth(int $months): array
    {
        $labels = [];
        $shop = [];
        $membership = [];

        try {
            for ($i = $months - 1; $i >= 0; $i--) {
                $monthStart = now()->subMonthsNoOverflow($i)->startOfMonth();
                $monthEnd = $monthStart->copy()->endOfMonth();

                $labels[] = $monthStart->format('M');

                $shop[] = (float) Payment::query()
                    ->where('status', 'completed')
                    ->whereBetween('completed_at', [$monthStart, $monthEnd])
                    ->sum('amount');

                $membership[] = (float) MembershipPayment::query()
                    ->where('status', MembershipPaymentStatus::Paid)
                    ->whereBetween('paid_at', [$monthStart, $monthEnd])
                    ->sum('amount_paid');
            }

            return ['labels' => $labels, 'shop' => $shop, 'membership' => $membership];
        } catch (Throwable) {
        }

        return ['labels' => [], 'shop' => [], 'membership' => []];
    }

    /**
     * Cumulative membership count as of each month-end.
     *
     * @return array{labels: list<string>, data: list<int>}
     */
    private function chartMembershipGrowth(int $months): array
    {
        $labels = [];
        $data = [];

        try {
            for ($i = $months - 1; $i >= 0; $i--) {
                $monthEnd = now()->subMonthsNoOverflow($i)->endOfMonth();

                $labels[] = $monthEnd->format('M');
                $data[] = Membership::query()->where('created_at', '<=', $monthEnd)->count();
            }

            return ['labels' => $labels, 'data' => $data];
        } catch (Throwable) {
        }

        return ['labels' => [], 'data' => []];
    }

    /**
     * New memberships created per month (non-cumulative).
     *
     * @return array{labels: list<string>, data: list<int>}
     */
    private function chartNewRegistrations(int $months): array
    {
        $labels = [];
        $data = [];

        try {
            for ($i = $months - 1; $i >= 0; $i--) {
                $monthStart = now()->subMonthsNoOverflow($i)->startOfMonth();
                $monthEnd = $monthStart->copy()->endOfMonth();

                $labels[] = $monthStart->format('M');
                $data[] = Membership::query()->whereBetween('created_at', [$monthStart, $monthEnd])->count();
            }

            return ['labels' => $labels, 'data' => $data];
        } catch (Throwable) {
        }

        return ['labels' => [], 'data' => []];
    }

    /**
     * @return array{labels: list<string>, data: list<int>}
     */
    private function chartRenewals(int $months): array
    {
        $labels = [];
        $data = [];

        try {
            for ($i = $months - 1; $i >= 0; $i--) {
                $monthStart = now()->subMonthsNoOverflow($i)->startOfMonth();
                $monthEnd = $monthStart->copy()->endOfMonth();

                $labels[] = $monthStart->format('M');
                $data[] = MembershipHistory::query()
                    ->where('event', MembershipHistoryEvent::Renewed)
                    ->whereBetween('created_at', [$monthStart, $monthEnd])
                    ->count();
            }

            return ['labels' => $labels, 'data' => $data];
        } catch (Throwable) {
        }

        return ['labels' => [], 'data' => []];
    }

    /**
     * Distinct members per satellite (scoped to users who actually hold a
     * membership, matching how totalMembers is defined in compute()).
     *
     * @return array{labels: list<string>, data: list<int>}
     */
    private function chartMembersPerSatellite(): array
    {
        try {
            $rows = Membership::query()
                ->join('users', 'users.id', '=', 'memberships.user_id')
                ->leftJoin('satellites', 'satellites.id', '=', 'users.satellite_id')
                ->selectRaw('COALESCE(satellites.name, ?) as satellite_name, COUNT(DISTINCT memberships.user_id) as member_count', ['Unassigned'])
                ->groupBy('satellite_name')
                ->orderByDesc('member_count')
                ->get();

            return [
                'labels' => $rows->pluck('satellite_name')->all(),
                'data' => $rows->pluck('member_count')->map(fn ($v) => (int) $v)->all(),
            ];
        } catch (Throwable) {
        }

        return ['labels' => [], 'data' => []];
    }

    /**
     * Gender distribution of distinct members (scoped the same way as
     * chartMembersPerSatellite()).
     *
     * @return array{labels: list<string>, data: list<int>}
     */
    private function chartGenderDistribution(): array
    {
        $labelMap = [
            'male' => 'Male',
            'female' => 'Female',
            'other' => 'Other',
            'prefer_not_to_say' => 'Prefer not to say',
            'Unspecified' => 'Unspecified',
        ];

        try {
            $rows = Membership::query()
                ->join('users', 'users.id', '=', 'memberships.user_id')
                ->selectRaw('COALESCE(users.gender, ?) as gender, COUNT(DISTINCT memberships.user_id) as member_count', ['Unspecified'])
                ->groupBy('gender')
                ->get();

            return [
                'labels' => $rows->pluck('gender')->map(fn ($g) => $labelMap[$g] ?? ucfirst((string) $g))->all(),
                'data' => $rows->pluck('member_count')->map(fn ($v) => (int) $v)->all(),
            ];
        } catch (Throwable) {
        }

        return ['labels' => [], 'data' => []];
    }
}
