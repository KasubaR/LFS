<?php

namespace App\Services;

use App\Exceptions\CodeException;
use App\Models\Event;
use App\Models\EventDistanceRoute;
use App\Support\Uuid;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class EventService
{
    public const SLUG_TAKEN_CODE = 'SLUG_TAKEN';

    public const DATE_ORDER_INVALID_CODE = 'DATE_ORDER_INVALID';

    public const INVALID_BANNER_URL_CODE = 'INVALID_BANNER_URL';

    public const FEATURE_ON_HOME_NO_BANNER_CODE = 'FEATURE_ON_HOME_NO_BANNER';

    /**
     * @param  array<string, mixed>  $opts
     * @return list<array<string, mixed>>
     */
    public function getEvents(array $opts = []): array
    {
        $category = $opts['category'] ?? null;
        $fromDate = $opts['fromDate'] ?? null;
        $toDate = $opts['toDate'] ?? null;
        $limit = (int) ($opts['limit'] ?? 50);

        $query = Event::query()->orderByDesc('event_date')->limit($limit);

        if ($category !== null && $category !== '') {
            $query->where('category', $category);
        }
        if ($fromDate !== null && $fromDate !== '') {
            $query->where('event_date', '>=', $fromDate);
        }
        if ($toDate !== null && $toDate !== '') {
            $query->where('event_date', '<=', $toDate);
        }

        return $query->get()->map(fn (Event $event) => $this->toEvent($event))->all();
    }

    public function getEventById(string $id): ?array
    {
        $event = Event::query()->find($id);

        return $event ? $this->hydrateEventDistanceRoutes($this->toEvent($event)) : null;
    }

    public function getEventBySlug(string $slug): ?array
    {
        $event = Event::query()->where('slug', $slug)->first();

        return $event ? $this->hydrateEventDistanceRoutes($this->toEvent($event)) : null;
    }

    /**
     * @return list<array{id: string, label: string, routeImage: ?string, sortOrder: int}>
     */
    public function fetchDistanceRoutes(string $eventId): array
    {
        try {
            $routes = EventDistanceRoute::query()
                ->where('event_id', $eventId)
                ->orderBy('sort_order')
                ->orderBy('label')
                ->get();
        } catch (Throwable) {
            return [];
        }

        return $routes->map(fn (EventDistanceRoute $route) => [
            'id' => (string) $route->id,
            'label' => (string) $route->label,
            'routeImage' => $this->sanitiseRouteImageForDisplay($route->route_image),
            'sortOrder' => (int) $route->sort_order,
        ])->all();
    }

    /**
     * @param  list<array{label: string, routeImage: string|null}>  $routes
     */
    public function replaceEventDistanceRoutes(string $eventId, array $routes): void
    {
        $prev = $this->fetchDistanceRoutes($eventId);
        $prevPaths = [];
        foreach ($prev as $p) {
            if (! empty($p['routeImage'])) {
                $prevPaths[] = $p['routeImage'];
            }
        }

        DB::transaction(function () use ($eventId, $routes): void {
            EventDistanceRoute::query()->where('event_id', $eventId)->delete();

            $sort = 0;
            $labels = [];
            foreach ($routes as $r) {
                $label = trim((string) ($r['label'] ?? ''));
                if ($label === '') {
                    continue;
                }

                $labels[] = $label;
                $img = $r['routeImage'] ?? null;
                $img = (is_string($img) && $img !== '') ? $this->sanitiseRouteImageForStorage($img) : null;

                EventDistanceRoute::query()->create([
                    'id' => Uuid::v4(),
                    'event_id' => $eventId,
                    'label' => $label,
                    'route_image' => $img,
                    'sort_order' => $sort++,
                ]);
            }

            $summary = $labels !== [] ? implode(', ', $labels) : '';
            Event::query()->whereKey($eventId)->update([
                'distance' => $summary,
                'updated_at' => now(),
            ]);
        });

        $newPaths = [];
        foreach ($routes as $r) {
            $img = (is_string($r['routeImage'] ?? null) && $r['routeImage'] !== '')
                ? $this->sanitiseRouteImageForStorage($r['routeImage'])
                : null;
            if ($img) {
                $newPaths[] = $img;
            }
        }
        $newPaths = array_unique($newPaths);

        foreach ($prevPaths as $old) {
            if ($old && str_starts_with($old, '/images/event-routes/') && ! in_array($old, $newPaths, true)) {
                $full = public_path(ltrim($old, '/\\'));
                if (is_file($full)) {
                    @unlink($full);
                }
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getUpcomingEvents(int $limit = 10): array
    {
        return Event::query()
            ->where('event_date', '>=', now())
            ->orderBy('event_date')
            ->limit($limit)
            ->get()
            ->map(fn (Event $event) => $this->toEvent($event))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getHomeHeroFeaturedEvents(int $limit = 20): array
    {
        $limit = max(1, min(30, $limit));

        return Event::query()
            ->where('feature_on_home', true)
            ->whereNotNull('banner_image')
            ->where('banner_image', '!=', '')
            ->whereRaw("TRIM(banner_image) <> ''")
            ->where('event_date', '>=', now())
            ->orderBy('event_date')
            ->limit($limit)
            ->get()
            ->map(fn (Event $event) => $this->toEvent($event))
            ->all();
    }

    public function getHomeHeroFeaturedEvent(): ?array
    {
        $events = $this->getHomeHeroFeaturedEvents(1);

        return $events[0] ?? null;
    }

    public function setHomePageHeroForEvent(string $id, bool $on): void
    {
        $ev = $this->getEventById($id);
        if ($ev === null) {
            throw new RuntimeException('Event not found.');
        }

        if ($on && empty($ev['bannerImage'])) {
            throw $this->codeException(
                'A banner image is required to feature an event on the home page.',
                self::FEATURE_ON_HOME_NO_BANNER_CODE
            );
        }

        Event::query()->whereKey($id)->update(['feature_on_home' => $on]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRecentEvents(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));

        return Event::query()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (Event $event) => $this->toEvent($event))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createEvent(array $data): array
    {
        $this->validateDateOrder(
            $data['eventDate'] ?? null,
            $data['registrationOpen'] ?? null,
            $data['registrationClose'] ?? null
        );
        $this->validateBannerUrl($data['bannerImage'] ?? null);
        $this->validateBrochureUrl($data['brochurePdf'] ?? null);

        $slug = ($data['slug'] ?? '') !== ''
            ? trim((string) $data['slug'])
            : $this->slugify($data['title'] ?? 'event');

        if ($this->getEventBySlug($slug) !== null) {
            throw $this->codeException('This slug is already in use. Choose another.', self::SLUG_TAKEN_CODE);
        }

        $wantsHomeHero = ! empty($data['featureOnHome']);
        if ($wantsHomeHero) {
            $banner = trim((string) ($data['bannerImage'] ?? ''));
            if ($banner === '') {
                throw $this->codeException(
                    'A banner image is required to feature an event on the home page.',
                    self::FEATURE_ON_HOME_NO_BANNER_CODE
                );
            }
        }

        $id = Uuid::v4();

        DB::transaction(function () use ($data, $id, $slug, $wantsHomeHero): void {
            Event::query()->create([
                'id' => $id,
                'title' => $data['title'],
                'slug' => $slug,
                'description' => $data['description'] ?? '',
                'location' => $data['location'] ?? '',
                'event_date' => $this->normaliseDatetime($data['eventDate'] ?? null),
                'distance' => $data['distance'] ?? '',
                'recurrence_type' => $data['recurrenceType'] ?? 'none',
                'recurrence_days' => $data['recurrenceDays'] ?? null,
                'category' => $data['category'] ?? '',
                'registration_open' => $this->normaliseDatetime($data['registrationOpen'] ?? null),
                'registration_close' => $this->normaliseDatetime($data['registrationClose'] ?? null),
                'registration_type' => $data['registrationType'] ?? 'open',
                'registration_link' => $data['registrationLink'] ?? null,
                'banner_image' => $data['bannerImage'] ?? null,
                'brochure_pdf' => $this->sanitiseBrochureUrl($data['brochurePdf'] ?? null),
                'created_by' => $data['createdBy'] ?? null,
                'feature_on_home' => $wantsHomeHero,
            ]);
        });

        return $this->getEventById($id) ?? [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateEvent(string $id, array $data): ?array
    {
        $this->validateDateOrder(
            $data['eventDate'] ?? null,
            $data['registrationOpen'] ?? null,
            $data['registrationClose'] ?? null
        );

        if (array_key_exists('bannerImage', $data)) {
            $this->validateBannerUrl($data['bannerImage']);
        }
        if (array_key_exists('brochurePdf', $data)) {
            $this->validateBrochureUrl($data['brochurePdf'] ?? null);
        }

        if (isset($data['slug'])) {
            $existing = $this->getEventBySlug($data['slug']);
            if ($existing !== null && $existing['id'] !== $id) {
                throw $this->codeException('This slug is already in use. Choose another.', self::SLUG_TAKEN_CODE);
            }
        }

        $map = [
            'title' => 'title',
            'slug' => 'slug',
            'description' => 'description',
            'location' => 'location',
            'eventDate' => 'event_date',
            'distance' => 'distance',
            'recurrenceType' => 'recurrence_type',
            'recurrenceDays' => 'recurrence_days',
            'category' => 'category',
            'registrationOpen' => 'registration_open',
            'registrationClose' => 'registration_close',
            'registrationType' => 'registration_type',
            'registrationLink' => 'registration_link',
            'bannerImage' => 'banner_image',
            'brochurePdf' => 'brochure_pdf',
            'createdBy' => 'created_by',
        ];

        $updates = [];
        foreach ($map as $camel => $snake) {
            if (! array_key_exists($camel, $data)) {
                continue;
            }

            if (in_array($camel, ['eventDate', 'registrationOpen', 'registrationClose'], true)) {
                $updates[$snake] = $this->normaliseDatetime($data[$camel]);
            } elseif ($camel === 'brochurePdf') {
                $updates[$snake] = $this->sanitiseBrochureUrl($data[$camel] ?? null);
            } else {
                $updates[$snake] = $data[$camel];
            }
        }

        if ($updates === []) {
            return $this->getEventById($id);
        }

        $updates['updated_at'] = now();
        Event::query()->whereKey($id)->update($updates);

        return $this->getEventById($id);
    }

    public function deleteEvent(string $id): void
    {
        foreach ($this->fetchDistanceRoutes($id) as $r) {
            $path = $r['routeImage'] ?? null;
            if (is_string($path) && str_starts_with($path, '/images/event-routes/')) {
                $full = public_path(ltrim($path, '/\\'));
                if (is_file($full)) {
                    @unlink($full);
                }
            }
        }

        Event::query()->whereKey($id)->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function toEvent(Event $event): array
    {
        return [
            '_id' => $event->id,
            'id' => $event->id,
            'title' => $event->title,
            'slug' => $event->slug,
            'description' => $event->description ?? '',
            'location' => $event->location ?? '',
            'eventDate' => $this->formatDateTime($event->event_date),
            'distance' => $event->distance ?? '',
            'recurrenceType' => $event->recurrence_type ?? 'none',
            'recurrenceDays' => $event->recurrence_days,
            'category' => $event->category ?? '',
            'registrationOpen' => $this->formatDateTime($event->registration_open),
            'registrationClose' => $this->formatDateTime($event->registration_close),
            'registrationType' => $event->registration_type ?? 'open',
            'registrationLink' => $event->registration_link,
            'bannerImage' => $this->sanitiseBannerUrl($event->banner_image),
            'brochurePdf' => $this->sanitiseBrochureUrl($event->brochure_pdf),
            'featureOnHome' => (bool) $event->feature_on_home,
            'createdBy' => $event->created_by,
            'createdAt' => $this->formatDateTime($event->created_at),
            'updatedAt' => $this->formatDateTime($event->updated_at),
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function hydrateEventDistanceRoutes(array $event): array
    {
        $event['distanceRoutes'] = $this->fetchDistanceRoutes($event['id']);
        if (! empty($event['distanceRoutes'])) {
            $event['distance'] = implode(
                ', ',
                array_map(static fn (array $r): string => $r['label'], $event['distanceRoutes'])
            );
        }

        return $event;
    }

    private function sanitiseBannerUrl(mixed $url): ?string
    {
        $value = trim((string) ($url ?? ''));
        if ($value === '') {
            return null;
        }
        if (str_starts_with($value, '/') && ! str_contains($value, '//')) {
            return $value;
        }

        $scheme = strtolower((string) (parse_url($value, PHP_URL_SCHEME) ?? ''));

        return in_array($scheme, ['http', 'https'], true) ? $value : null;
    }

    private function sanitiseBrochureUrl(mixed $url): ?string
    {
        return $this->sanitiseBannerUrl($url);
    }

    private function sanitiseRouteImageForStorage(mixed $url): ?string
    {
        $value = trim((string) ($url ?? ''));
        if ($value === '') {
            return null;
        }
        if (str_starts_with($value, '/') && ! str_contains($value, '//')) {
            return $value;
        }

        $scheme = strtolower((string) (parse_url($value, PHP_URL_SCHEME) ?? ''));

        return in_array($scheme, ['http', 'https'], true) ? $value : null;
    }

    private function sanitiseRouteImageForDisplay(mixed $url): ?string
    {
        return $this->sanitiseRouteImageForStorage($url);
    }

    private function validateBannerUrl(mixed $bannerImage): void
    {
        $value = trim((string) ($bannerImage ?? ''));
        if ($value === '') {
            return;
        }
        if (str_starts_with($value, '/') && ! str_contains($value, '//')) {
            return;
        }

        $scheme = strtolower((string) (parse_url($value, PHP_URL_SCHEME) ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw $this->codeException('Banner image must be an http or https URL.', self::INVALID_BANNER_URL_CODE);
        }
    }

    private function validateBrochureUrl(mixed $url): void
    {
        $value = trim((string) ($url ?? ''));
        if ($value === '') {
            return;
        }
        if (str_starts_with($value, '/') && ! str_contains($value, '//')) {
            return;
        }

        $scheme = strtolower((string) (parse_url($value, PHP_URL_SCHEME) ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw $this->codeException(
                'Event brochure must be a site path (starting with /) or an http(s) URL.',
                self::INVALID_BANNER_URL_CODE
            );
        }
    }

    private function validateDateOrder(mixed $eventDate, mixed $regOpen, mixed $regClose): void
    {
        $tEvent = $this->parseTimestamp($eventDate);
        $tOpen = $this->parseTimestamp($regOpen);
        $tClose = $this->parseTimestamp($regClose);

        if ($tOpen !== null && $tClose !== null && $tOpen >= $tClose) {
            throw $this->codeException(
                'Registration open date must be before registration close date.',
                self::DATE_ORDER_INVALID_CODE
            );
        }
        if ($tClose !== null && $tEvent !== null && $tClose >= $tEvent) {
            throw $this->codeException(
                'Registration close date must be before the event date.',
                self::DATE_ORDER_INVALID_CODE
            );
        }
        if ($tOpen !== null && $tEvent !== null && $tOpen >= $tEvent) {
            throw $this->codeException(
                'Registration open date must be before the event date.',
                self::DATE_ORDER_INVALID_CODE
            );
        }
    }

    private function normaliseDatetime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $string = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $string)) {
            return str_replace('T', ' ', $string).':00';
        }

        return str_replace('T', ' ', rtrim($string, 'Z'));
    }

    private function parseTimestamp(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $timestamp = strtotime((string) $value);

        return $timestamp !== false ? $timestamp : null;
    }

    private function slugify(string $str): string
    {
        $str = strtolower(trim($str));
        $str = preg_replace('/[^a-z0-9\s\-]/', '', $str) ?? '';
        $str = preg_replace('/[\s\-]+/', '-', $str) ?? '';

        return trim($str, '-');
    }

    private function codeException(string $message, string $code): CodeException
    {
        return new CodeException($message, $code);
    }

    private function formatDateTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }
}
