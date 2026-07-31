<?php

namespace App\Http\Controllers;

use App\Services\EventService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class EventController extends Controller
{
    public function __construct(
        private readonly EventService $eventService,
    ) {}

    public function index(): View
    {
        try {
            $events = $this->eventService->getEvents(['limit' => 100]);
        } catch (Throwable $e) {
            Log::error('[LFS] Events listing error: '.$e->getMessage());
            $events = [];
        }

        return view('pages.events', [
            'title' => 'Events & Races',
            'description' => 'Upcoming and past LFS events — road races, LSD runs, training camps, and community events in Lusaka, Zambia.',
            'page' => 'events',
            'events' => $events,
            'bodyClass' => 'page-no-hero',
            'extraStyles' => '<link rel="stylesheet" href="/css/events.css">',
            'extraScripts' => '<script src="/js/events.js"></script>',
        ]);
    }

    public function show(string $slug): View|RedirectResponse
    {
        try {
            $event = $this->eventService->getEventBySlug($slug);
        } catch (Throwable $e) {
            Log::error('[LFS] Event detail error: '.$e->getMessage());
            $event = null;
        }

        if (! $event) {
            return redirect('/events');
        }

        return view('pages.event-details', [
            'title' => $event['title'],
            'description' => $event['description'] ?? '',
            'page' => 'events',
            'event' => $event,
            'extraStyles' => '<link rel="stylesheet" href="/css/events.css">',
            'extraScripts' => '<script src="/js/events.js"></script>',
        ]);
    }
}
