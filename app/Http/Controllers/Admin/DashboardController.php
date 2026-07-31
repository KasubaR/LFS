<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ActivityService;
use App\Services\DashboardStatsService;
use App\Services\EventService;
use Illuminate\Contracts\View\View;
use Throwable;

class DashboardController extends Controller
{
    public function __construct(
        private readonly EventService $eventService,
        private readonly ActivityService $activityService,
        private readonly DashboardStatsService $dashboardStats,
    ) {}

    public function index(): View
    {
        $upcomingEvents = [];
        try {
            $upcomingEvents = $this->eventService->getUpcomingEvents(5);
        } catch (Throwable) {
        }

        $stats = $this->dashboardStats->compute();
        $salesChart = $this->dashboardStats->computeSalesChart();

        $recentActivity = [];
        try {
            $recentActivity = $this->activityService->getRecentActivity(8);
        } catch (Throwable) {
        }

        return view('admin.dashboard.index', [
            'pageTitle' => 'Dashboard',
            'activePage' => 'dashboard',
            'stats' => [
                'totalMembers' => $stats['totalMembers'],
                'activeMembers' => $stats['activeMembers'],
                'newMembersThisMonth' => $stats['newMembersThisMonth'],
                'newMessages' => $stats['newMessages'],
                'upcomingEvents' => count($upcomingEvents),
                'pendingOrders' => $stats['pendingOrders'],
                'shopRevenue' => $stats['shopRevenue'],
                'membershipRevenue' => $stats['membershipRevenue'],
                'galleryUploads' => 0,
            ],
            'counts' => [
                'pendingMembers' => 0,
                'pendingOrders' => $stats['pendingOrders'],
                'pendingGallery' => 0,
                'newMessages' => $stats['newMessages'],
            ],
            'notifications' => ['unread' => 0, 'items' => []],
            'recentActivity' => $recentActivity,
            'pendingTasks' => [
                'orders' => $stats['pendingOrders'],
                'messages' => $stats['newMessages'],
                'memberships' => $stats['pendingMemberships'],
            ],
            'systemAlerts' => $this->dashboardStats->computeSystemAlerts(),
            'chartData' => [
                'members' => [],
                'events' => [],
                'sales' => $salesChart['sales'],
                'gallery' => $salesChart['gallery'],
            ],
            'upcomingEvents' => $upcomingEvents,
        ]);
    }

    public function activity(): View
    {
        $recentActivity = [];
        try {
            $recentActivity = $this->activityService->getRecentActivity(50);
        } catch (Throwable) {
        }

        return view('admin.activity.index', [
            'pageTitle' => 'Activity',
            'activePage' => 'dashboard',
            'counts' => [
                'pendingMembers' => 0,
                'pendingOrders' => 0,
                'pendingGallery' => 0,
                'newMessages' => 0,
            ],
            'breadcrumbs' => [
                ['label' => 'Admin', 'url' => '/admin/dashboard'],
                ['label' => 'Activity'],
            ],
            'recentActivity' => $recentActivity,
        ]);
    }
}
