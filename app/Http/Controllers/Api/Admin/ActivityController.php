<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ActivityController extends Controller
{
    public function __construct(
        private readonly ActivityService $activityService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $limit = max(1, min(50, (int) $request->query('limit', 20)));
        $items = [];

        try {
            $items = $this->activityService->getRecentActivity($limit);
        } catch (Throwable) {
        }

        return response()->json(['ok' => true, 'items' => $items]);
    }
}
