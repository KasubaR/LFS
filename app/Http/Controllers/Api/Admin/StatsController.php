<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\DashboardStatsService;
use App\Services\EventService;
use Illuminate\Http\JsonResponse;
use Throwable;

class StatsController extends Controller
{
    public function __construct(
        private readonly EventService $eventService,
        private readonly DashboardStatsService $dashboardStats,
    ) {}

    public function index(): JsonResponse
    {
        $upcomingEvents = 0;
        try {
            $upcomingList = $this->eventService->getUpcomingEvents(100);
            $upcomingEvents = is_array($upcomingList) ? count($upcomingList) : 0;
        } catch (Throwable) {
        }

        $stats = $this->dashboardStats->compute();

        return response()->json([
            'ok' => true,
            'pendingOrders' => $stats['pendingOrders'],
            'newMessages' => $stats['newMessages'],
            'upcomingEvents' => $upcomingEvents,
            'totalMembers' => $stats['totalMembers'],
            'activeMembers' => $stats['activeMembers'],
            'shopRevenue' => $stats['shopRevenue'],
            'membershipRevenue' => $stats['membershipRevenue'],
            'galleryUploads' => 0,
        ]);
    }
}
