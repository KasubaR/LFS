<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Response;

use App\Enums\EventCategory;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use App\Services\EventService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use Throwable;

class EventController extends Controller
{
    public function __construct(
        private readonly EventService $eventService,
    ) {}

    public function index(Request $request): View
    {
        $category = $request->query('category', '');
        $fromDate = $request->query('fromDate', '');
        $toDate = $request->query('toDate', '');

        $opts = ['limit' => 100];
        if ($category) {
            $opts['category'] = $category;
        }
        if ($fromDate) {
            $opts['fromDate'] = $fromDate;
        }
        if ($toDate) {
            $opts['toDate'] = $toDate;
        }

        $events = [];
        $eventsError = null;
        try {
            $events = $this->eventService->getEvents($opts);
        } catch (Throwable $e) {
            $eventsError = $e->getMessage() ?: 'Could not load events. Check database connection.';
            Log::error('[LFS Admin] EventController::index — '.$e->getMessage());
        }

        return view('admin.events.list', [
            'pageTitle' => 'Events',
            'activePage' => 'events',
            'events' => $events,
            'eventsError' => $eventsError,
            'eventCategories' => EventCategory::ALL,
            'filterCategory' => $category,
            'filterFromDate' => $fromDate,
            'filterToDate' => $toDate,
            'breadcrumbs' => [
                ['label' => 'Admin', 'url' => '/admin'],
                ['label' => 'Events'],
            ],
        ]);
    }

    public function create(): View
    {
        return view('admin.events.event-form', [
            'pageTitle' => 'New Event',
            'activePage' => 'events',
            'event' => null,
            'eventCategories' => EventCategory::ALL,
            'isEdit' => false,
            'breadcrumbs' => [
                ['label' => 'Admin', 'url' => '/admin'],
                ['label' => 'Events', 'url' => '/admin/events'],
                ['label' => 'New Event'],
            ],
            'extraScripts' => '<script defer src="/admin/js/event-distances.js"></script>',
        ]);
    }

    public function store(Request $request): View|RedirectResponse
    {
        if ($error = $this->uploadError($request)) {
            return $this->renderFormWithError(null, false, 'New Event', $request->all(), $error);
        }

        $body = $request->all();
        $isWeekly = ($body['recurrenceType'] ?? 'none') === 'weekly';
        if (empty($body['title']) || (! $isWeekly && empty($body['eventDate']))) {
            return $this->renderFormWithError(
                null,
                false,
                'New Event',
                $body,
                $isWeekly ? 'Title is required.' : 'Title and event date are required.'
            );
        }

        try {
            $distRoutes = $this->collectDistanceRoutesFromRequest($request);
            $created = $this->eventService->createEvent($this->buildEventPayload($body, null, $distRoutes));
            $this->eventService->replaceEventDistanceRoutes(
                (string) $created['id'],
                $distRoutes
            );

            return redirect('/admin/events');
        } catch (Throwable $e) {
            return $this->renderFormWithError(null, false, 'New Event', $body, $e->getMessage());
        }
    }

    public function edit(string $id): View|RedirectResponse
    {
        $event = $this->safeGetById($id);
        if (! $event) {
            return redirect('/admin/events');
        }

        return view('admin.events.event-form', [
            'pageTitle' => 'Edit Event',
            'activePage' => 'events',
            'event' => $event,
            'eventCategories' => EventCategory::ALL,
            'isEdit' => true,
            'breadcrumbs' => [
                ['label' => 'Admin', 'url' => '/admin'],
                ['label' => 'Events', 'url' => '/admin/events'],
                ['label' => $event['title']],
            ],
            'extraScripts' => '<script defer src="/admin/js/event-distances.js"></script>',
        ]);
    }

    public function update(Request $request, string $id): View|RedirectResponse
    {
        if ($error = $this->uploadError($request)) {
            $existing = $this->safeGetById($id);

            return $this->renderFormWithError(
                $existing ? array_merge($existing, $request->all()) : $request->all(),
                true,
                $request->input('title', $existing['title'] ?? 'Edit'),
                array_merge($existing ?? [], $request->all()),
                $error
            );
        }

        $body = $request->all();
        $isWeekly = ($body['recurrenceType'] ?? 'none') === 'weekly';
        if (empty($body['title']) || (! $isWeekly && empty($body['eventDate']))) {
            return $this->renderFormWithError(null, true, $body['title'] ?? 'Edit', $body, $isWeekly ? 'Title is required.' : 'Title and event date are required.');
        }

        $existing = $this->safeGetById($id);
        if (! $existing) {
            return redirect('/admin/events');
        }

        $featureOnHome = (string) ($body['featureOnHome'] ?? '') === '1';
        $distRoutes = $this->collectDistanceRoutesFromRequest($request);
        $oldBrochure = $existing['brochurePdf'] ?? null;

        try {
            $updated = $this->eventService->updateEvent($id, $this->buildEventPayload($body, $existing, $distRoutes));
            if (! $updated) {
                return redirect('/admin/events');
            }

            $this->eventService->setHomePageHeroForEvent($id, $featureOnHome);
            $this->eventService->replaceEventDistanceRoutes($id, $distRoutes);
            $this->cleanupReplacedFiles($existing, $body, $oldBrochure);

            return redirect('/admin/events');
        } catch (Throwable $e) {
            return $this->renderFormWithError(
                array_merge($existing, $body),
                true,
                $body['title'] ?? $existing['title'],
                array_merge($existing, $body),
                $e->getMessage()
            );
        }
    }

    public function destroy(string $id): RedirectResponse
    {
        $event = $this->safeGetById($id);
        $this->eventService->deleteEvent($id);

        if ($event) {
            $this->deleteLocalFile($event['bannerImage'] ?? null, '/images/events/');
            $this->deleteLocalFile($event['brochurePdf'] ?? null, '/files/event-brochures/');
        }

        return redirect('/admin/events');
    }

    /**
     * @param  array<string, mixed>|null  $existing
     * @param  list<array{label: string, routeImage: string|null}>  $distRoutes
     * @return array<string, mixed>
     */
    private function buildEventPayload(array $body, ?array $existing, array $distRoutes): array
    {
        $recDays = isset($body['recurrence_days']) && is_array($body['recurrence_days'])
            ? implode(',', array_map('trim', $body['recurrence_days']))
            : null;
        $distSummary = $this->distanceSummaryFromRoutes($distRoutes);

        return [
            'title' => trim($body['title']),
            'slug' => isset($body['slug']) ? trim($body['slug']) : null,
            'description' => $body['description'] ?? '',
            'location' => $body['location'] ?? '',
            'eventDate' => $body['eventDate'] ?: null,
            'distance' => $distSummary,
            'recurrenceType' => $body['recurrenceType'] ?? 'none',
            'recurrenceDays' => $recDays,
            'category' => $body['category'] ?? '',
            'registrationOpen' => trim((string) ($body['registrationOpen'] ?? '')) !== '' ? trim((string) $body['registrationOpen']) : null,
            'registrationClose' => trim((string) ($body['registrationClose'] ?? '')) !== '' ? trim((string) $body['registrationClose']) : null,
            'registrationType' => $body['registrationType'] ?? 'open',
            'registrationLink' => trim((string) ($body['registrationLink'] ?? '')) !== '' ? trim((string) $body['registrationLink']) : null,
            'bannerImage' => $this->resolveUploadedBanner($body['bannerImage'] ?? null),
            'brochurePdf' => $this->resolveEventBrochureFromRequest($existing),
            'featureOnHome' => isset($body['featureOnHome']) && (string) ($body['featureOnHome'] ?? '') === '1',
        ];
    }

    private function uploadError(Request $request): ?string
    {
        return $request->input('_bannerUploadError')
            ?: $request->input('_distanceRouteUploadError')
            ?: $request->input('_brochureUploadError');
    }

    /**
     * @return list<array{label: string, routeImage: string|null}>
     */
    private function collectDistanceRoutesFromRequest(Request $request): array
    {
        $labels   = $request->input('dist_label', []);
        $existing = $request->input('dist_route_existing', []);
        $stored   = $request->input('dist_route_stored', []);
        $files    = $request->file('dist_route_file') ?? [];

        if (! is_array($labels)) {
            $labels = [];
        }
        if (! is_array($existing)) {
            $existing = [];
        }
        if (! is_array($stored)) {
            $stored = [];
        }
        if (! is_array($files)) {
            $files = [];
        }

        $routes = [];
        for ($i = 0, $n = count($labels); $i < $n; $i++) {
            $label = trim((string) ($labels[$i] ?? ''));
            if ($label === '') {
                continue;
            }

            // Priority: newly uploaded file > stored (AJAX) path > existing path
            $img  = null;
            $file = $files[$i] ?? null;
            if ($file && $file->isValid()) {
                $dir = public_path('images/events/routes');
                if (! is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
                $ext      = strtolower($file->getClientOriginalExtension() ?: 'jpg');
                $filename = 'route_'.uniqid().'.'.$ext;
                $file->move($dir, $filename);
                $img = '/images/events/routes/'.$filename;
            } else {
                $img = $stored[$i] ?? null;
                if (! is_string($img) || $img === '') {
                    $img = trim((string) ($existing[$i] ?? ''));
                }
            }

            $routes[] = ['label' => $label, 'routeImage' => ($img === '' || $img === null) ? null : $img];
        }

        return $routes;
    }

    /**
     * @param  list<array{label: string, routeImage: string|null}>  $routes
     */
    private function distanceSummaryFromRoutes(array $routes): string
    {
        $labels = [];
        foreach ($routes as $r) {
            $l = trim((string) ($r['label'] ?? ''));
            if ($l !== '') {
                $labels[] = $l;
            }
        }

        return $labels === [] ? '' : implode(', ', $labels);
    }

  /**
     * @param  array<string, mixed>|null  $existing
     */
    private function resolveEventBrochureFromRequest(?array $existing): ?string
    {
        if ((string) request()->input('remove_brochure', '') === '1') {
            return null;
        }

        $file = request()->file('brochurePdfFile');
        if ($file && $file->isValid()) {
            $dir = public_path('files/event-brochures');
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $filename = 'brochure_'.uniqid().'.pdf';
            $file->move($dir, $filename);

            return '/files/event-brochures/'.$filename;
        }

        $stored = request()->input('brochure_pdf_stored', '');
        if (is_string($stored) && $stored !== '') {
            return trim($stored);
        }
        $text = trim((string) request()->input('brochurePdf', ''));
        if ($text !== '') {
            return $text;
        }

        return $existing['brochurePdf'] ?? null;
    }

    private function resolveUploadedBanner(?string $bodyUrl): ?string
    {
        $file = request()->file('bannerImageFile');
        if ($file && $file->isValid()) {
            $dir = public_path('images/events');
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $ext      = strtolower($file->getClientOriginalExtension() ?: 'jpg');
            $filename = 'banner_'.uniqid().'.'.$ext;
            $file->move($dir, $filename);

            return '/images/events/'.$filename;
        }

        return $bodyUrl ?: null;
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $body
     */
    private function cleanupReplacedFiles(array $existing, array $body, ?string $oldBrochure): void
    {
        $bannerImage = $this->resolveUploadedBanner($body['bannerImage'] ?? null);
        $brochurePdf = $this->resolveEventBrochureFromRequest($existing);

        $hadNewLocalBanner = $bannerImage !== null && str_starts_with($bannerImage, '/images/events/');
        $oldWasLocal = ! empty($existing['bannerImage']) && str_starts_with($existing['bannerImage'], '/images/events/');
        if ($hadNewLocalBanner && $oldWasLocal && $existing['bannerImage'] !== $bannerImage) {
            $this->deleteLocalFile($existing['bannerImage'], '/images/events/');
        }

        if (is_string($oldBrochure) && str_starts_with($oldBrochure, '/files/event-brochures/') && $oldBrochure !== $brochurePdf) {
            $this->deleteLocalFile($oldBrochure, '/files/event-brochures/');
        }
    }

    private function deleteLocalFile(?string $path, string $prefix): void
    {
        if (! $path || ! str_starts_with($path, $prefix)) {
            return;
        }
        $full = public_path(ltrim($path, '/'));
        if (file_exists($full)) {
            @unlink($full);
        }
    }

    /**
     * @param  array<string, mixed>|null  $event
     */
    private function renderFormWithError(?array $event, bool $isEdit, string $pageTitle, array $formData, string $error): View
    {
        return view('admin.events.event-form', [
            'pageTitle' => $pageTitle,
            'activePage' => 'events',
            'event' => $event ?? $formData,
            'eventCategories' => EventCategory::ALL,
            'isEdit' => $isEdit,
            'error' => $error,
            'breadcrumbs' => [
                ['label' => 'Admin', 'url' => '/admin'],
                ['label' => 'Events', 'url' => '/admin/events'],
                ['label' => $pageTitle],
            ],
            'extraScripts' => '<script defer src="/admin/js/event-distances.js"></script>',
        ]);
    }

  private function safeGetById(string $id): ?array
    {
        try {
            return $this->eventService->getEventById($id);
        } catch (Throwable) {
            return null;
        }
    }
}
