<?php

namespace Tests\Feature;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Public payment and partner endpoints cap abusive request volumes.
 */
class PaymentEndpointThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_webhook_is_rate_limited_per_ip(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->postJson('/shop/checkout/webhook', ['x' => 1], ['X-Lenco-Signature' => 'bad'])->assertStatus(401);
        }

        $this->postJson('/shop/checkout/webhook', ['x' => 1], ['X-Lenco-Signature' => 'bad'])->assertStatus(429);
    }

    public function test_membership_webhook_is_rate_limited_per_ip(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->postJson('/account/payment/webhook', ['x' => 1], ['X-Lenco-Signature' => 'bad'])->assertStatus(401);
        }

        $this->postJson('/account/payment/webhook', ['x' => 1], ['X-Lenco-Signature' => 'bad'])->assertStatus(429);
    }

    public function test_partner_api_limits_requests_with_bad_keys_by_ip(): void
    {
        RateLimiter::for('partner-ip', fn () => Limit::perMinute(3)->by('test-ip')->response(
            fn ($request, array $headers) => response()->json(['error' => ['code' => 'rate_limited', 'message' => 'x']], 429, $headers)
        ));

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/members/verify', ['membership_number' => 'LFS-000001', 'surname' => 'A'], ['Authorization' => 'Bearer nope.nope'])
                ->assertStatus(401);
        }

        $this->postJson('/api/v1/members/verify', ['membership_number' => 'LFS-000001', 'surname' => 'A'], ['Authorization' => 'Bearer nope.nope'])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'rate_limited');
    }

    public function test_partner_ip_limiter_is_registered_with_a_generous_default(): void
    {
        $this->assertNotNull(RateLimiter::limiter('partner-ip'));
    }
}
