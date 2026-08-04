<?php

namespace App\Services;

use App\Enums\MembershipHistoryEvent;
use App\Models\MembershipHistory;

class MemberDashboardService
{
    private const EVENT_LABELS = [
        MembershipHistoryEvent::Activated => 'Membership activated',
        MembershipHistoryEvent::Renewed => 'Membership renewed',
        MembershipHistoryEvent::Expired => 'Membership expired',
        MembershipHistoryEvent::PlanChanged => 'Plan changed',
        MembershipHistoryEvent::ManualAdjustment => 'Membership updated',
        MembershipHistoryEvent::Imported => 'Membership imported',
        MembershipHistoryEvent::AdminEdit => 'Membership edited by admin',
        MembershipHistoryEvent::PaymentReceived => 'Payment received',
        MembershipHistoryEvent::Submitted => 'Application submitted',
        MembershipHistoryEvent::Cancelled => 'Membership cancelled',
    ];

    /**
     * Recent membership lifecycle events for this member, newest first.
     *
     * @return list<array{label: string, fromStatus: ?string, toStatus: ?string, date: string}>
     */
    public function getMembershipTimeline(int $userId, int $limit = 10): array
    {
        return MembershipHistory::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (MembershipHistory $entry) => [
                'label' => self::EVENT_LABELS[$entry->event] ?? ucfirst(str_replace('_', ' ', (string) $entry->event)),
                'fromStatus' => $entry->from_status,
                'toStatus' => $entry->to_status,
                'date' => (string) $entry->created_at,
            ])
            ->values()
            ->all();
    }
}
