<?php

namespace Tests\Feature;

use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

/**
 * Home page hero for featured events: the banner is shown whole (not zoomed
 * and cropped) and the title, date and buttons are never clipped.
 */
class HomeHeroFeaturedTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    private function featured(array $overrides = []): Event
    {
        return Event::query()->create(array_merge([
            'id' => (string) Str::uuid(),
            'title' => 'LFS End Of Year Party',
            'slug' => 'eoy-'.Str::random(6),
            'event_date' => now()->addDays(20),
            'feature_on_home' => true,
            'banner_image' => '/images/events/poster.jpg',
        ], $overrides));
    }

    public function test_featured_event_banner_is_rendered_whole_over_a_blurred_backdrop(): void
    {
        $this->featured();

        $this->get('/')
            ->assertOk()
            ->assertSee('home-hero__slide--featured', false)
            ->assertSee('home-hero__poster-wrap', false)
            ->assertSee('class="home-hero__poster"', false)
            ->assertSee('--hero-bg: url(', false)
            ->assertSee('/images/events/poster.jpg', false);
    }

    public function test_featured_hero_shows_title_date_and_both_buttons(): void
    {
        $event = $this->featured();

        $this->get('/')
            ->assertOk()
            ->assertSee('LFS End Of Year Party')
            ->assertSee('View Event Details')
            ->assertSee('All events')
            ->assertSee('/events/'.$event->slug, false);
    }

    public function test_hero_is_marked_featured_so_small_screens_leave_room_for_the_poster(): void
    {
        $this->featured();

        $html = $this->get('/')->getContent();

        $this->assertMatchesRegularExpression('/<section id="hero"\s+class="[^"]*home-hero--featured-active/', $html);
    }

    public function test_without_a_featured_event_the_hero_is_unchanged(): void
    {
        $this->featured(['feature_on_home' => false]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('home-hero__slide--featured', $html);
        $this->assertStringNotContainsString('home-hero__poster', $html);
        // (The slider script mentions this class name, so check the <section> itself.)
        $this->assertDoesNotMatchRegularExpression('/<section id="hero"\s+class="[^"]*home-hero--featured-active/', $html);
        $this->assertStringContainsString("Zambia's Biggest", $html);
    }

    public function test_past_events_and_events_without_a_banner_are_not_featured(): void
    {
        $this->featured(['event_date' => now()->subDay()]);
        $this->featured(['banner_image' => null, 'slug' => 'no-banner-'.Str::random(4)]);

        $this->get('/')->assertOk()->assertDontSee('home-hero__slide--featured', false);
    }

    public function test_banner_urls_cannot_break_out_of_the_inline_style(): void
    {
        $this->featured(['banner_image' => "https://cdn.test/a b'c\"d(e).jpg"]);

        $html = $this->get('/')->assertOk()->getContent();

        preg_match('/--hero-bg: url\(\'([^\']*)\'\)/', $html, $m);
        $this->assertNotEmpty($m, 'the backdrop style is present');
        foreach (["'", '"', '(', ')', ' '] as $bad) {
            $this->assertStringNotContainsString($bad, $m[1], "raw '{$bad}' would break the CSS url()");
        }
        $this->assertStringContainsString('a%20b%27c%22d%28e%29.jpg', $m[1]);
    }

    public function test_each_featured_event_gets_its_own_whole_poster_slide(): void
    {
        $this->featured(['banner_image' => '/images/events/one.jpg', 'slug' => 'one-'.Str::random(4)]);
        $this->featured(['banner_image' => '/images/events/two.jpg', 'slug' => 'two-'.Str::random(4), 'title' => 'LFS Christmas Run']);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'class="home-hero__poster"'));
        $this->assertStringContainsString('LFS Christmas Run', $html);
    }

    public function test_hero_stylesheet_keeps_panels_in_flow_and_the_poster_uncropped(): void
    {
        $css = (string) file_get_contents(public_path('css/home.css'));

        // Panels share one grid cell, so the container grows with its content.
        $this->assertMatchesRegularExpression('/\.home-hero__featured-panels\s*\{[^}]*display:\s*grid/s', $css);
        $this->assertMatchesRegularExpression('/\.home-hero__featured-panel\s*\{[^}]*grid-area:\s*1\s*\/\s*1/s', $css);
        $this->assertDoesNotMatchRegularExpression('/\.home-hero__featured-panel\s*\{[^}]*position:\s*absolute/s', $css, 'absolute panels clipped the title and buttons');
        $this->assertDoesNotMatchRegularExpression('/\.home-hero__featured-panels\s*\{[^}]*min-height/s', $css, 'a fixed min-height cut the buttons off');

        // The poster is contained, never cover-cropped, and clears the fixed navbar.
        $this->assertMatchesRegularExpression('/\.home-hero__poster\s*\{[^}]*object-fit:\s*contain/s', $css);
        $this->assertMatchesRegularExpression('/\.home-hero__poster-wrap\s*\{[^}]*top:\s*5\.5rem/s', $css);
    }

    public function test_admin_form_gives_the_recommended_banner_size(): void
    {
        $html = $this->actingAsAdmin()->get('/admin/events/create')->assertOk()->getContent();

        $this->assertStringContainsString('Recommended banner size', $html);
        $this->assertStringContainsString('1920 × 1080 px', $html);
        $this->assertStringContainsString('16:9', $html);
        $this->assertStringContainsString('1280 × 720', $html);
        $this->assertStringContainsString('1080 × 1350 px', $html, 'portrait posters are covered too');
        $this->assertStringContainsString('WhatsApp are compressed', $html);
        $this->assertStringContainsString('5&nbsp;MB', $html);
        $this->assertStringContainsString('home page hero', $html);
    }
}
