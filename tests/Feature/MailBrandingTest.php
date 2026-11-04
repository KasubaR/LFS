<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\BirthdayGreetingNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

/**
 * Every email the site sends goes through the same markdown layout, so the
 * LFS look (resources/views/vendor/mail) applies to all of them.
 */
class MailBrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://www.lfszambia.run']);
    }

    private function html(MailMessage $mail): string
    {
        return (string) $mail->render();
    }

    /**
     * @return array<string, MailMessage>
     */
    private function sampleEmails(): array
    {
        $user = User::factory()->create(['other_names' => 'Chanda', 'last_name' => 'Mwale']);

        return [
            'reset password' => (new ResetPasswordNotification('token-123'))->toMail($user),
            'verify email' => (new VerifyEmailNotification)->toMail($user),
            'birthday' => (new BirthdayGreetingNotification)->toMail($user),
            'plain markdown message' => (new MailMessage)->greeting('Hello')->line('Body text')->action('Do it', 'https://example.test/x'),
        ];
    }

    public function test_every_email_has_the_lfs_logo_header_and_flag_stripe(): void
    {
        foreach ($this->sampleEmails() as $name => $mail) {
            $html = $this->html($mail);

            $this->assertStringContainsString('https://www.lfszambia.run/images/email/lfs-logo.png', $html, "{$name}: logo");
            $this->assertStringContainsString('alt="LFS — Lusaka Fitness Squad"', $html, "{$name}: logo alt text");
            $this->assertStringContainsString('href="https://www.lfszambia.run"', $html, "{$name}: logo links home");
            foreach (['#0f0f0f', '#198a4e', '#c0392b', '#e07b39'] as $colour) {
                $this->assertStringContainsString($colour, $html, "{$name}: brand colour {$colour}");
            }
        }
    }

    public function test_every_email_has_the_lfs_footer_with_working_contact_details(): void
    {
        foreach ($this->sampleEmails() as $name => $mail) {
            $html = $this->html($mail);

            $this->assertStringContainsString('Lusaka Fitness Squad', $html, $name);
            $this->assertStringContainsString("We're In This Together.", $html, $name);
            $this->assertStringContainsString('https://facebook.com/lfszambia', $html, $name);
            $this->assertStringContainsString('https://instagram.com/lfszambia', $html, $name);
            $this->assertStringContainsString('https://wa.me/260767023948', $html, $name);
            $this->assertStringContainsString('+260 767 023 948', $html, $name);
            $this->assertStringContainsString('mailto:support@lfszambia.run', $html, $name);
            $this->assertStringContainsString('© '.date('Y').' Lusaka Fitness Squad.', $html, $name);
        }
    }

    public function test_footer_contact_email_is_not_the_sender_address(): void
    {
        // A test or no-reply sender must never leak into the "write to us" line.
        config(['mail.from.address' => 'no-reply@sender.test']);

        $html = $this->html((new MailMessage)->line('Hi'));

        $this->assertStringContainsString('mailto:support@lfszambia.run', $html);
        $this->assertStringNotContainsString('no-reply@sender.test', $html);
    }

    public function test_contact_details_can_be_changed_from_config(): void
    {
        config(['mail.contact_address' => 'hello@lfs.test', 'mail.contact_whatsapp' => '260971234567']);

        $html = $this->html((new MailMessage)->line('Hi'));

        $this->assertStringContainsString('mailto:hello@lfs.test', $html);
        $this->assertStringContainsString('https://wa.me/260971234567', $html);
        $this->assertStringContainsString('+260 971 234 567', $html);
    }

    public function test_buttons_are_lfs_green_and_headings_use_the_display_font(): void
    {
        $html = $this->html((new MailMessage)->greeting('Payment successful')->line('Thanks')->action('View your order', 'https://example.test/o'));

        // Laravel inlines the theme CSS into the markup.
        $this->assertMatchesRegularExpression('/<a [^>]*class="button button-primary"[^>]*style="[^"]*background-color:\s*#198a4e/i', $html);
        $this->assertMatchesRegularExpression('/<h1[^>]*style="[^"]*Bebas Neue/i', $html);
        $this->assertStringContainsString('fonts.googleapis.com/css2?family=Bebas+Neue', $html);
        $this->assertDoesNotMatchRegularExpression('/background-color:\s*#18181b/i', $html, 'the stock black Laravel button is gone');
    }

    public function test_the_layout_is_responsive_and_light_only(): void
    {
        $html = $this->html((new MailMessage)->line('Hi'));

        $this->assertStringContainsString('name="viewport"', $html);
        $this->assertStringContainsString('max-width: 600px', $html);
        $this->assertStringContainsString('name="color-scheme" content="light"', $html);
    }

    public function test_no_laravel_branding_is_left(): void
    {
        $html = $this->html((new MailMessage)->line('Hi')->action('Go', 'https://example.test'));

        $this->assertStringNotContainsString('laravel.com', $html);
        $this->assertStringNotContainsString('Laravel', $html);
    }

    public function test_the_logo_is_a_real_png_at_retina_size(): void
    {
        $path = public_path('images/email/lfs-logo.png');

        $this->assertFileExists($path);
        $this->assertSame("\x89PNG", substr((string) file_get_contents($path), 0, 4), 'a PNG, not an SVG (most email clients block SVG)');

        [$width, $height] = getimagesize($path);
        $this->assertGreaterThanOrEqual(420, $width, 'at least 2x the 210px it is shown at');
        $this->assertEqualsWithDelta(1024 / 343.15, $width / $height, 0.02, 'the logo keeps its proportions');
    }

    public function test_the_plain_text_version_is_branded_too(): void
    {
        $mail = (new MailMessage)->greeting('Hello')->line('Body text')->action('Go', 'https://example.test/go');

        $text = (string) app(\Illuminate\Mail\Markdown::class)->renderText('notifications::email', $mail->data());

        $this->assertStringContainsString('LFS — Lusaka Fitness Squad', $text);
        $this->assertStringContainsString('WhatsApp +260767023948', $text);
        $this->assertStringContainsString('support@lfszambia.run', $text);
        $this->assertStringContainsString('Lusaka Fitness Squad. All rights reserved.', $text);
    }

    public function test_ticket_emails_use_the_branded_layout(): void
    {
        $this->seed(\Database\Seeders\SatelliteSeeder::class);
        $this->seed(\Database\Seeders\MembershipPlanSeeder::class);

        $event = \App\Models\Event::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'title' => 'End of Year Party', 'slug' => 'eoy',
            'event_date' => now()->addDays(10), 'registration_type' => 'paid',
            'ticket_price_member' => 550, 'ticket_price_non_member' => 650,
        ]);
        $order = \App\Models\EventTicketOrder::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'order_number' => 'LFSEV-20261104-BRAND', 'event_id' => $event->id,
            'buyer_name' => 'Pat Buyer', 'buyer_email' => 'pat@example.test', 'standard_unit_price' => 650,
            'quantity' => 1, 'total' => 650, 'status' => 'paid', 'paid_at' => now(),
        ]);
        \App\Models\EventTicket::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'order_id' => $order->id, 'event_id' => $event->id,
            'position' => 1, 'attendee_name' => 'Ann Banda', 'ticket_reference' => 'LFSEV-20261104-BRAND-1', 'price' => 650,
        ]);

        $html = $this->html((new \App\Notifications\EventTicketConfirmationNotification($order))->toMail(new \Illuminate\Notifications\AnonymousNotifiable));

        $this->assertStringContainsString('Payment successful', $html);
        $this->assertStringContainsString('Ann Banda', $html);
        $this->assertStringContainsString('images/email/lfs-logo.png', $html);
        $this->assertStringContainsString('mailto:support@lfszambia.run', $html);
        $this->assertMatchesRegularExpression('/class="button button-primary"[^>]*style="[^"]*#198a4e/i', $html);
    }
}
