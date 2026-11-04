<?php

namespace App\Services;

use App\Exceptions\CodeException;
use App\Models\Event;
use App\Models\EventDistanceRoute;
use App\Models\EventTicketOrder;
use App\Services\Tickets\TicketAvailability;
use App\Support\Uuid;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class EventService
{
    public const SLUG_TAKEN_CODE = 'SLUG_TAKEN';

    public const DATE_ORDER_INVALID_CODE = 'DATE_ORDER_INVALID';

    public const INVALID_BANNER_URL_CODE = 'INVALID_BANNER_URL';

    public const FEATURE_ON_HOME_NO_BANNER_CODE = 'FEATURE_ON_HOME_NO_BANNER';

    public const TICKETING_INVALID_CODE = 'TICKETING_INVALID';

    public const CAPACITY_BELOW_SOLD_CODE = 'CAPACITY_BELOW_SOLD';

    public const TICKET_ORDERS_EXIST_CODE = 'TICKET_ORDERS_EXIST';

    public const REGISTRATION_TYPE_PAID = 'paid';

    /** @var list<string> */
    public const TICKET_STATUSES = ['scheduled', 'postponed', 'cancelled'];

    /**
     * Must match the directory Admin\EventController uploads distance-route
     * images into ({@see \App\Http\Controllers\Admin\EventController::collectDistanceRoutesFromRequest()}).
     */
    private const ROUTE_IMAGE_PATH_PREFIX = '/images/events/routes/';

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
            if (mb_strlen($summary) > 255) {
                $summary = mb_substr($summary, 0, 252).'…';
            }
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
            if ($old && ! in_array($old, $newPaths, true)) {
                $this->deleteLocalRouteImage($old);
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
        $ticketing = $this->normaliseTicketing($data);

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

        DB::transaction(function () use ($data, $id, $slug, $wantsHomeHero, $ticketing): void {
            Event::query()->create(array_merge($ticketing, [
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
            ]));
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

        $ticketing = [];
        if (array_key_exists('registrationType', $data)) {
            $ticketing = $this->normaliseTicketing($data);
            $this->guardTicketingChange($id, $data, $ticketing);
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

        $updates = array_merge($updates, $ticketing);

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
            if (is_string($path)) {
                $this->deleteLocalRouteImage($path);
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
            'ticketPriceMember' => $event->ticket_price_member !== null ? (float) $event->ticket_price_member : null,
            'ticketPriceNonMember' => $event->ticket_price_non_member !== null ? (float) $event->ticket_price_non_member : null,
            'ticketCapacity' => $event->ticket_capacity !== null ? (int) $event->ticket_capacity : null,
            'ticketSalesOpen' => (bool) ($event->ticket_sales_open ?? true),
            'ticketStatus' => $event->ticket_status ?? 'scheduled',
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

    /**
     * Deletes a route-image file from disk, confined to the route-image
     * upload directory. Resolves the real path first so a '..' segment (or
     * a symlink) can't be used to delete a file outside that directory —
     * defense in depth alongside the '..' rejection in sanitiseRouteImageForStorage().
     */
    private function deleteLocalRouteImage(string $path): void
    {
        if ($path === '' || str_contains($path, '..') || ! str_starts_with($path, self::ROUTE_IMAGE_PATH_PREFIX)) {
            return;
        }

        $full = public_path(ltrim($path, '/\\'));
        $realFull = realpath($full);
        $realDir = realpath(public_path(ltrim(self::ROUTE_IMAGE_PATH_PREFIX, '/')));

        if ($realFull === false || $realDir === false || ! str_starts_with($realFull, $realDir)) {
            return;
        }

        @unlink($realFull);
    }

    /**
     * A same-site path is only "safe" if it's rooted at the public web root
     * (starts with a single '/'), isn't protocol-relative ('//host/...'),
     * and contains no '..' segment that could traverse outside the intended
     * upload directory when later passed to public_path()/unlink().
     */
    private function isSafeLocalPath(string $value): bool
    {
        return str_starts_with($value, '/')
            && ! str_starts_with($value, '//')
            && ! str_contains($value, '..');
    }

    private function sanitiseBannerUrl(mixed $url): ?string
    {
        $value = trim((string) ($url ?? ''));
        if ($value === '') {
            return null;
        }
        if ($this->isSafeLocalPath($value)) {
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
        if ($this->isSafeLocalPath($value)) {
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
        if ($this->isSafeLocalPath($value)) {
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
        if ($this->isSafeLocalPath($value)) {
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

    /**
     * Tickets already committed against an event's capacity: tickets on paid
     * orders, on pending orders still inside their reservation window, and on
     * orders held for payment review (money received, not yet resolved).
     */
    public function committedTicketCount(string $eventId): int
    {
        // Single source of truth for what holds capacity.
        return app(TicketAvailability::class)->committedTickets($eventId);
    }

    /**
     * Validates and normalises the ticketing fields into events-table columns.
     *
     * Prices and capacity are only kept for 'paid' events; any other
     * registration type stores them as null so stale prices never leak.
     * 'paid' events must have both prices, member <= non-member, a positive
     * integer capacity if one is set, and may not recur weekly (one capacity
     * and ticket set does not make sense across repeating dates).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normaliseTicketing(array $data): array
    {
        $type = (string) ($data['registrationType'] ?? 'open');
        $status = (string) ($data['ticketStatus'] ?? 'scheduled');
        if (! in_array($status, self::TICKET_STATUSES, true)) {
            throw $this->codeException('Invalid ticket status.', self::TICKETING_INVALID_CODE);
        }

        if ($type !== self::REGISTRATION_TYPE_PAID) {
            return [
                'ticket_price_member' => null,
                'ticket_price_non_member' => null,
                'ticket_capacity' => null,
                'ticket_sales_open' => true,
                'ticket_status' => 'scheduled',
            ];
        }

        if (($data['recurrenceType'] ?? 'none') === 'weekly') {
            throw $this->codeException(
                'Ticketed events must be one-off. Weekly recurrence is not supported for paid events.',
                self::TICKETING_INVALID_CODE
            );
        }

        $member = $this->parseMoney($data['ticketPriceMember'] ?? null);
        $nonMember = $this->parseMoney($data['ticketPriceNonMember'] ?? null);
        if ($member === null || $nonMember === null) {
            throw $this->codeException(
                'Enter both the member price and the non-member price for a paid event.',
                self::TICKETING_INVALID_CODE
            );
        }
        if ($member > $nonMember) {
            throw $this->codeException(
                'The member price cannot be higher than the non-member price.',
                self::TICKETING_INVALID_CODE
            );
        }

        $capacityRaw = $data['ticketCapacity'] ?? null;
        $capacity = null;
        if ($capacityRaw !== null && trim((string) $capacityRaw) !== '') {
            if (! preg_match('/^\d+$/', trim((string) $capacityRaw)) || (int) $capacityRaw < 1) {
                throw $this->codeException(
                    'Capacity must be a whole number of 1 or more, or left empty for unlimited.',
                    self::TICKETING_INVALID_CODE
                );
            }
            $capacity = (int) $capacityRaw;
        }

        $salesOpen = array_key_exists('ticketSalesOpen', $data)
            ? filter_var($data['ticketSalesOpen'], FILTER_VALIDATE_BOOLEAN)
            : true;

        return [
            'ticket_price_member' => $member,
            'ticket_price_non_member' => $nonMember,
            'ticket_capacity' => $capacity,
            'ticket_sales_open' => $salesOpen,
            'ticket_status' => $status,
        ];
    }

    private function parseMoney(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $string = trim(str_replace(',', '', (string) $value));
        if ($string === '' || ! preg_match('/^\d+(\.\d{1,2})?$/', $string)) {
            return null;
        }
        $amount = (float) $string;

        return $amount > 0 ? $amount : null;
    }

    /**
     * Refuses ticketing changes that would break existing sales:
     * capacity may not drop below tickets already committed, and a paid event
     * with live orders cannot be switched to another registration type.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $ticketing  normalised columns
     */
    private function guardTicketingChange(string $id, array $data, array $ticketing): void
    {
        $existing = Event::query()->find($id);
        if ($existing === null) {
            return;
        }

        $hasOrders = EventTicketOrder::query()
            ->where('event_id', $id)
            ->whereIn('status', [
                EventTicketOrder::STATUS_PAID,
                EventTicketOrder::STATUS_PAYMENT_REVIEW_REQUIRED,
                EventTicketOrder::STATUS_PENDING_PAYMENT,
            ])
            ->exists();

        $newType = (string) ($data['registrationType'] ?? $existing->registration_type);
        if ($hasOrders && $existing->registration_type === self::REGISTRATION_TYPE_PAID
            && $newType !== self::REGISTRATION_TYPE_PAID) {
            throw $this->codeException(
                'This event has ticket orders, so it cannot be changed away from paid ticketing. Cancel the orders first.',
                self::TICKET_ORDERS_EXIST_CODE
            );
        }

        $newCapacity = $ticketing['ticket_capacity'] ?? null;
        if ($newCapacity !== null) {
            $committed = $this->committedTicketCount($id);
            if ($newCapacity < $committed) {
                throw $this->codeException(
                    "Capacity cannot be lower than the {$committed} ticket(s) already sold or reserved.",
                    self::CAPACITY_BELOW_SOLD_CODE
                );
            }
        }

        $oldCapacity = $existing->ticket_capacity;
        if ($oldCapacity !== $newCapacity) {
            Log::info('[EventService] Ticket capacity changed', [
                'eventId' => $id,
                'old' => $oldCapacity,
                'new' => $newCapacity,
                'adminId' => session(config('admin.session_user_key')),
            ]);
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
