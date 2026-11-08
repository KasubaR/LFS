<?php

namespace Tests\Feature;

use App\Exceptions\LencoApiException;
use App\Services\LencoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exercises the REAL signature check (no mocks): Lenco signs the raw body with
 * HMAC-SHA512, keyed by the SHA-256 hash of the API secret key.
 */
class LencoWebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_KEY = 'test-api-secret-not-real';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.lenco.api_secret_key' => self::FAKE_KEY]);
    }

    private function sign(string $body, string $apiKey = self::FAKE_KEY): string
    {
        return hash_hmac('sha512', $body, hash('sha256', $apiKey));
    }

    public function test_valid_signature_is_accepted(): void
    {
        $body = '{"event":"collection.successful","data":{"reference":"R1"}}';

        $this->assertTrue((new LencoService)->verifyWebhookSignature($body, ['X-Lenco-Signature' => [$this->sign($body)]]));
    }

    public function test_tampered_body_is_rejected(): void
    {
        $body = '{"data":{"reference":"R1","amount":"10"}}';
        $sig = $this->sign($body);

        $this->assertFalse((new LencoService)->verifyWebhookSignature(str_replace('10', '1', $body), ['x-lenco-signature' => $sig]));
    }

    public function test_signature_from_another_key_is_rejected(): void
    {
        $body = '{"a":1}';

        $this->assertFalse((new LencoService)->verifyWebhookSignature($body, ['x-lenco-signature' => $this->sign($body, 'other')]));
    }

    public function test_old_sha256_scheme_is_rejected(): void
    {
        $body = '{"a":1}';

        $this->assertFalse((new LencoService)->verifyWebhookSignature($body, ['x-lenco-signature' => hash_hmac('sha256', $body, self::FAKE_KEY)]));
    }

    public function test_missing_header_is_rejected(): void
    {
        $this->assertFalse((new LencoService)->verifyWebhookSignature('{}', []));
    }

    public function test_fails_closed_when_no_api_key_is_configured(): void
    {
        config(['services.lenco.api_secret_key' => '']);
        $body = '{}';

        $this->assertFalse((new LencoService)->verifyWebhookSignature($body, ['x-lenco-signature' => $this->sign($body, '')]));
    }

    public function test_shop_webhook_endpoint_returns_401_for_bad_signature(): void
    {
        $this->postJson('/shop/checkout/webhook', ['data' => ['reference' => 'X']], ['X-Lenco-Signature' => 'bad'])
            ->assertStatus(401);
    }

    public function test_membership_webhook_endpoint_returns_401_for_bad_signature(): void
    {
        $this->postJson('/account/payment/webhook', ['data' => ['reference' => 'X']], ['X-Lenco-Signature' => 'bad'])
            ->assertStatus(401);
    }

    public function test_signed_webhook_with_unknown_payment_is_acknowledged(): void
    {
        $body = json_encode(['data' => ['reference' => 'UNKNOWN', 'status' => 'successful']]);

        $this->call('POST', '/shop/checkout/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_LENCO_SIGNATURE' => $this->sign($body),
        ], $body)->assertOk()->assertJson(['ok' => true]);
    }

    public function test_customer_message_never_leaks_provider_or_internal_text(): void
    {
        $lenco = new LencoService;

        $internal = $lenco->customerMessage(new \RuntimeException('LENCO_API_SECRET_KEY environment variable is not set'));
        $this->assertStringNotContainsString('LENCO', $internal);

        $upstream = $lenco->customerMessage(new LencoApiException('SQLSTATE boom at 10.0.0.5', 500, true));
        $this->assertStringNotContainsString('SQLSTATE', $upstream);

        $this->assertStringContainsString('mobile money number', $lenco->customerMessage(new LencoApiException('Invalid phone number', 422)));
    }
}
