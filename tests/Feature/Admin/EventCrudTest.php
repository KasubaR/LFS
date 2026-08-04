<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Models\Event;
use App\Services\EventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class EventCrudTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    /**
     * Exact absolute paths this test wrote to the real public/ webroot
     * (these upload endpoints use public_path()/move(), not a fake disk).
     * Tracked explicitly and deleted one by one in tearDown — never via a
     * glob pattern, which could otherwise match and delete pre-existing
     * committed fixture files that happen to share the same prefix.
     *
     * @var list<string>
     */
    private array $filesToCleanUp = [];

    protected function tearDown(): void
    {
        foreach ($this->filesToCleanUp as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Saturday LSD Run',
            'slug' => 'saturday-lsd-run-'.Str::random(8),
            'description' => 'A test event.',
            'location' => 'Arcades, Lusaka',
            'recurrenceType' => 'none',
            'eventDate' => now()->addDays(10)->format('Y-m-d\TH:i'),
            'category' => 'LSD',
            'registrationType' => 'open',
            'dist_label' => ['10K'],
            'dist_route_existing' => [''],
        ], $overrides);
    }

    public function test_events_officer_can_create_event_and_created_by_is_recorded(): void
    {
        $officer = $this->makeAdminUser(['role' => AdminRole::EventsOfficer]);

        $response = $this->actingAsAdmin($officer)->post('/admin/events', $this->minimalPayload());

        $response->assertRedirect('/admin/events');
        $this->assertDatabaseCount('events', 1);

        $event = Event::query()->first();
        $this->assertSame($officer->id, $event->created_by);
    }

    public function test_read_only_auditor_cannot_create_event(): void
    {
        $auditor = $this->makeAdminUser(['role' => AdminRole::ReadOnlyAuditor]);

        $response = $this->actingAsAdmin($auditor)->post('/admin/events', $this->minimalPayload());

        $response->assertForbidden();
        $this->assertDatabaseCount('events', 0);
    }

    public function test_read_only_auditor_can_view_but_not_delete(): void
    {
        $auditor = $this->makeAdminUser(['role' => AdminRole::ReadOnlyAuditor]);
        $event = Event::query()->create([
            'id' => (string) Str::uuid(),
            'title' => 'Existing Event',
            'slug' => 'existing-event-'.Str::random(8),
            'event_date' => now()->addDays(5),
        ]);

        // Reads (including the create-event form) are allowed for read-only roles...
        $this->actingAsAdmin($auditor)->get('/admin/events/list')->assertOk();
        $this->actingAsAdmin($auditor)->get('/admin/events/create')->assertOk();

        // ...but any mutation, including delete, is blocked.
        $this->actingAsAdmin($auditor)
            ->post('/admin/events/'.$event->id.'/delete')
            ->assertForbidden();
        $this->assertDatabaseCount('events', 1);
    }

    public function test_weekly_recurring_event_without_date_saves_successfully(): void
    {
        $response = $this->actingAsAdmin()->post('/admin/events', $this->minimalPayload([
            'recurrenceType' => 'weekly',
            'recurrence_days' => ['saturday'],
            'eventDate' => '',
        ]));

        $response->assertRedirect('/admin/events');

        $event = Event::query()->first();
        $this->assertNotNull($event);
        $this->assertNull($event->event_date);
        $this->assertSame('weekly', $event->recurrence_type);
    }

    public function test_one_off_event_still_requires_a_date(): void
    {
        $response = $this->actingAsAdmin()->post('/admin/events', $this->minimalPayload([
            'recurrenceType' => 'none',
            'eventDate' => '',
        ]));

        $response->assertOk(); // re-renders the form with an error, no redirect
        $this->assertDatabaseCount('events', 0);
    }

    public function test_banner_path_traversal_is_rejected(): void
    {
        $response = $this->actingAsAdmin()->post('/admin/events', $this->minimalPayload([
            'bannerImage' => '/images/events/../../../../.env',
        ]));

        $response->assertOk();
        $this->assertDatabaseCount('events', 0);
    }

    public function test_uploading_a_disguised_non_image_file_as_banner_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('shell.jpg', 10, 'application/x-php');

        $response = $this->actingAsAdmin()->post('/admin/events', array_merge(
            $this->minimalPayload(),
            ['bannerImageFile' => $file]
        ));

        $response->assertOk();
        $this->assertDatabaseCount('events', 0);
    }

    public function test_oversized_banner_upload_is_rejected(): void
    {
        $file = UploadedFile::fake()->image('banner.jpg')->size(6000); // > 5MB limit

        $response = $this->actingAsAdmin()->post('/admin/events', array_merge(
            $this->minimalPayload(),
            ['bannerImageFile' => $file]
        ));

        $response->assertOk();
        $this->assertDatabaseCount('events', 0);
    }

    public function test_valid_banner_image_upload_is_stored_with_a_safe_extension(): void
    {
        $file = UploadedFile::fake()->image('banner.jpg', 200, 200);

        $response = $this->actingAsAdmin()->post('/admin/events', array_merge(
            $this->minimalPayload(),
            ['bannerImageFile' => $file]
        ));

        $response->assertRedirect('/admin/events');

        $event = Event::query()->first();
        $this->filesToCleanUp[] = public_path(ltrim((string) $event->banner_image, '/'));

        $this->assertStringStartsWith('/images/events/banner_', $event->banner_image);
        $this->assertMatchesRegularExpression('/\.(jpg|jpeg|png|webp)$/', $event->banner_image);
        $this->assertFileExists(public_path(ltrim($event->banner_image, '/')));
    }

    public function test_distance_route_image_is_deleted_from_disk_when_replaced(): void
    {
        $eventService = app(EventService::class);

        $created = $eventService->createEvent([
            'title' => 'Route Cleanup Test',
            'slug' => 'route-cleanup-test-'.Str::random(8),
            'eventDate' => now()->addDays(5)->format('Y-m-d H:i:s'),
        ]);

        $dir = public_path('images/events/routes');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $filename = 'route_test_'.Str::random(8).'.jpg';
        file_put_contents($dir.DIRECTORY_SEPARATOR.$filename, 'fake image content');
        $imgPath = '/images/events/routes/'.$filename;
        $this->filesToCleanUp[] = public_path(ltrim($imgPath, '/'));

        $eventService->replaceEventDistanceRoutes($created['id'], [
            ['label' => '10K', 'routeImage' => $imgPath],
        ]);
        $this->assertFileExists(public_path(ltrim($imgPath, '/')));

        // Replacing the routes without that image should delete the orphaned file.
        $eventService->replaceEventDistanceRoutes($created['id'], [
            ['label' => '10K', 'routeImage' => null],
        ]);

        $this->assertFileDoesNotExist(public_path(ltrim($imgPath, '/')));
    }

    public function test_distance_summary_is_capped_instead_of_erroring(): void
    {
        $eventService = app(EventService::class);

        $created = $eventService->createEvent([
            'title' => 'Long Distances Test',
            'slug' => 'long-distances-test-'.Str::random(8),
            'eventDate' => now()->addDays(5)->format('Y-m-d H:i:s'),
        ]);

        $routes = [];
        for ($i = 0; $i < 6; $i++) {
            $routes[] = ['label' => str_repeat('X', 60).$i, 'routeImage' => null];
        }

        $eventService->replaceEventDistanceRoutes($created['id'], $routes);

        $event = Event::query()->find($created['id']);
        $this->assertLessThanOrEqual(255, mb_strlen((string) $event->distance));
    }

    public function test_admin_list_date_range_filter_renders_selected_values(): void
    {
        $response = $this->actingAsAdmin()->get('/admin/events/list?fromDate=2026-01-01&toDate=2026-12-31');

        $response->assertOk();
        $response->assertSee('value="2026-01-01"', false);
        $response->assertSee('value="2026-12-31"', false);
    }
}
