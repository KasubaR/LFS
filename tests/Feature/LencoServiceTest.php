<?php

namespace Tests\Feature;

use App\Exceptions\LencoApiException;
use App\Services\LencoService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * LencoService against a faked HTTP layer: request shape, error handling,
 * retries and status mapping. No real network, no real money.
 */
class LencoServiceTest extends TestCase
{
    private const BASE = 'https://lenco.test/access/v2';

    private const KEY = 'test-api-secret-not-real';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.lenco.base_url' => self::BASE,
            'services.lenco.api_secret_key' => self::KEY,
            'services.lenco.retry_base_ms' => 1,
        ]);
    }

    /** @return array<string, mixed> */
    private function order(): array
    {
        return [
            'orderNumber' => 'LFS-20260101-ABC12',
            'totals' => ['total' => 250],
            'currency' => 'ZMW',
            'country' => 'ZM',
        ];
    }

    /** @return array<string, mixed> */
    private function collection(string $status = 'pay-offline', array $extra = []): array
    {
        return ['status' => true, 'message' => '', 'data' => array_merge([
            'id' => 'col-1', 'lencoReference' => 'LR-1', 'reference' => 'REF-1', 'status' => $status,
            'amount' => '250.00', 'currency' => 'ZMW',
        ], $extra)];
    }

    // ── Request shape ────────────────────────────────────────────────────────

    public function test_initiate_sends_the_expected_request(): void
    {
        Http::fake(['*' => Http::response($this->collection(), 200)]);

        (new LencoService)->initiateMobileMoneyPayment($this->order(), '0971234567', 'MTN', 'REF-1');

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->method() === 'POST'
                && $request->url() === self::BASE.'/collections/mobile-money'
                && $request->hasHeader('Authorization', 'Bearer '.self::KEY)
                && $request->hasHeader('Accept', 'application/json')
                && $request->isJson()
                && $body['phone'] === '0971234567'
                && $body['operator'] === 'mtn'
                && $body['amount'] === 250.0
                && $body['currency'] === 'ZMW'
                && $body['reference'] === 'REF-1'
                && $body['country'] === 'ZM'
                && str_contains($body['description'], 'LFS-20260101-ABC12');
        });
        Http::assertSentCount(1);
    }

    public function test_initiate_generates_a_unique_reference_when_none_is_given(): void
    {
        Http::fake(['*' => Http::response($this->collection(), 200)]);
        $service = new LencoService;

        $a = $service->initiateMobileMoneyPayment($this->order(), '0971234567', 'airtel');
        $b = $service->initiateMobileMoneyPayment($this->order(), '0971234567', 'airtel');

        $sent = Http::recorded()->map(fn ($pair) => $pair[0]->data()['reference'])->all();
        $this->assertCount(2, array_unique($sent));
        $this->assertStringStartsWith('LFS-LFS-20260101-ABC12-', $sent[0]);
        $this->assertNotNull($a);
        $this->assertNotNull($b);
    }

    public function test_initiate_returns_a_normalised_result(): void
    {
        Http::fake(['*' => Http::response($this->collection('pay-offline', [
            'paymentInstructions' => 'Dial *115#', 'expiresAt' => '2026-01-01T10:00:00Z',
        ]), 200)]);

        $result = (new LencoService)->initiateMobileMoneyPayment($this->order(), '0971234567', 'mtn', 'REF-1');

        $this->assertSame('col-1', $result['transactionId']);
        $this->assertSame('LR-1', $result['lencoReference']);
        $this->assertSame('REF-1', $result['reference']);
        $this->assertSame('pay-offline', $result['status']);
        $this->assertSame('pending', $result['internalStatus']);
        $this->assertSame('Dial *115#', $result['paymentInstructions']);
    }

    public function test_verify_by_reference_uses_the_status_endpoint(): void
    {
        Http::fake(['*' => Http::response($this->collection('successful'), 200)]);

        $result = (new LencoService)->verifyPayment('LFS REF/1');

        $this->assertSame('completed', $result['internalStatus']);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && $r->url() === self::BASE.'/collections/status/LFS+REF%2F1'
            && $r->hasHeader('Authorization', 'Bearer '.self::KEY));
    }

    public function test_verify_by_id_uses_the_collection_endpoint(): void
    {
        Http::fake(['*' => Http::response($this->collection('failed', ['reasonForFailure' => 'Insufficient funds']), 200)]);

        $result = (new LencoService)->verifyPayment('col-1', false);

        $this->assertSame('failed', $result['internalStatus']);
        $this->assertSame('Insufficient funds', $result['failureReason']);
        Http::assertSent(fn (Request $r) => $r->url() === self::BASE.'/collections/col-1');
    }

    // ── Status mapping ───────────────────────────────────────────────────────

    /** @return array<string, array{0: string, 1: string}> */
    public static function statusMap(): array
    {
        return [
            'successful' => ['successful', 'completed'],
            'success alias' => ['success', 'completed'],
            'completed alias' => ['completed', 'completed'],
            'failed' => ['failed', 'failed'],
            'cancelled' => ['cancelled', 'cancelled'],
            'expired' => ['expired', 'cancelled'],
            'processing' => ['processing', 'processing'],
            'pending' => ['pending', 'pending'],
            'pay-offline' => ['pay-offline', 'pending'],
            'otp-required' => ['otp-required', 'pending'],
            'unknown value' => ['something-new', 'pending'],
            'mixed case' => ['SUCCESSFUL', 'completed'],
        ];
    }

    /** @dataProvider statusMap */
    #[\PHPUnit\Framework\Attributes\DataProvider('statusMap')]
    public function test_lenco_statuses_map_to_internal_statuses(string $lenco, string $internal): void
    {
        $this->assertSame($internal, (new LencoService)->mapLencoStatus($lenco));
    }

    // ── 404 handling ─────────────────────────────────────────────────────────

    public function test_verify_treats_a_404_as_not_found_yet(): void
    {
        Http::fake(['*' => Http::response(['status' => false, 'message' => 'Collection not found'], 404)]);

        $this->assertSame(
            ['status' => 'pending', 'internalStatus' => 'pending', 'found' => false],
            (new LencoService)->verifyPayment('REF-X')
        );
        Http::assertSentCount(1);
    }

    public function test_verify_does_not_hide_other_errors_that_mention_not_found(): void
    {
        Http::fake(['*' => Http::response(['status' => false, 'message' => 'Account not found'], 400)]);

        $this->expectException(LencoApiException::class);
        (new LencoService)->verifyPayment('REF-X');
    }

    // ── Client and server errors ─────────────────────────────────────────────

    /** @return array<string, array{0: int}> */
    public static function nonRetryableStatuses(): array
    {
        return ['400' => [400], '401' => [401], '403' => [403], '422' => [422]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonRetryableStatuses')]
    public function test_client_errors_are_raised_once_and_never_retried(int $status): void
    {
        Http::fake(['*' => Http::response(['status' => false, 'message' => 'Nope'], $status)]);

        try {
            (new LencoService)->initiateMobileMoneyPayment($this->order(), '0971234567', 'mtn', 'REF-1');
            $this->fail('expected LencoApiException');
        } catch (LencoApiException $e) {
            $this->assertSame($status, $e->getHttpStatus());
            $this->assertFalse($e->isRetryable());
            $this->assertFalse($e->outcomeIsUnknown(), 'a 4xx means nothing was charged');
            $this->assertSame('Nope', $e->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_429_is_retried_then_raised_as_retryable(): void
    {
        Http::fake(['*' => Http::response(['status' => false, 'message' => 'Slow down'], 429)]);

        try {
            (new LencoService)->verifyPayment('REF-1');
            $this->fail('expected LencoApiException');
        } catch (LencoApiException $e) {
            $this->assertSame(429, $e->getHttpStatus());
            $this->assertTrue($e->isRetryable());
        }

        Http::assertSentCount(4); // first try + 3 retries
    }

    public function test_500_is_retried_then_raised_as_unknown_outcome(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Boom'], 500)]);

        try {
            (new LencoService)->initiateMobileMoneyPayment($this->order(), '0971234567', 'mtn', 'REF-1');
            $this->fail('expected LencoApiException');
        } catch (LencoApiException $e) {
            $this->assertSame(500, $e->getHttpStatus());
            $this->assertTrue($e->outcomeIsUnknown(), 'a 5xx may have been charged');
        }

        Http::assertSentCount(4);
    }

    public function test_a_retry_that_succeeds_returns_the_result_and_reuses_the_reference(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['message' => 'Boom'], 503)
            ->push($this->collection('pay-offline'), 200),
        ]);

        $result = (new LencoService)->initiateMobileMoneyPayment($this->order(), '0971234567', 'mtn', 'REF-1');

        $this->assertSame('pending', $result['internalStatus']);
        Http::assertSentCount(2);
        $references = Http::recorded()->map(fn ($pair) => $pair[0]->data()['reference'])->unique()->all();
        $this->assertSame(['REF-1'], $references, 'a retry must never create a second charge');
    }

    // ── Network failures ─────────────────────────────────────────────────────

    public function test_a_timeout_is_retried_then_raised_as_an_unknown_outcome(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        try {
            (new LencoService)->initiateMobileMoneyPayment($this->order(), '0971234567', 'mtn', 'REF-1');
            $this->fail('expected LencoApiException');
        } catch (LencoApiException $e) {
            $this->assertSame(0, $e->getHttpStatus());
            $this->assertTrue($e->isRetryable());
            $this->assertTrue($e->outcomeIsUnknown());
        }

        $this->assertSame(4, $attempts, 'first try + 3 retries');
    }

    public function test_a_dns_failure_is_handled_the_same_way(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host: lenco.test'));

        $this->expectException(LencoApiException::class);
        (new LencoService)->verifyPayment('REF-1');
    }

    // ── Bad responses ────────────────────────────────────────────────────────

    public function test_non_json_body_is_rejected_without_retrying(): void
    {
        Http::fake(['*' => Http::response('<html>Bad gateway</html>', 200, ['Content-Type' => 'text/html'])]);

        try {
            (new LencoService)->verifyPayment('REF-1');
            $this->fail('expected LencoApiException');
        } catch (LencoApiException $e) {
            $this->assertStringContainsString('Invalid JSON', $e->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_empty_body_is_rejected(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $this->expectException(LencoApiException::class);
        (new LencoService)->verifyPayment('REF-1');
    }

    public function test_status_false_with_http_200_is_an_error(): void
    {
        Http::fake(['*' => Http::response(['status' => false, 'message' => 'Invalid operator'], 200)]);

        try {
            (new LencoService)->initiateMobileMoneyPayment($this->order(), '0971234567', 'mtn', 'REF-1');
            $this->fail('expected LencoApiException');
        } catch (LencoApiException $e) {
            $this->assertSame('Invalid operator', $e->getMessage());
            $this->assertFalse($e->outcomeIsUnknown());
        }
    }

    public function test_a_response_missing_expected_fields_does_not_crash(): void
    {
        Http::fake(['*' => Http::response(['status' => true, 'data' => []], 200)]);

        $result = (new LencoService)->initiateMobileMoneyPayment($this->order(), '0971234567', 'mtn', 'REF-1');

        $this->assertSame('pending', $result['internalStatus']);
        $this->assertSame('REF-1', $result['reference'], 'falls back to our own reference');
        $this->assertNull($result['transactionId']);
    }

    // ── Credentials ──────────────────────────────────────────────────────────

    public function test_missing_api_key_fails_before_any_request_is_made(): void
    {
        config(['services.lenco.api_secret_key' => '']);
        Http::fake();

        $service = new LencoService;
        $this->assertFalse($service->isConfigured());

        try {
            $service->verifyPayment('REF-1');
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(LencoApiException::class, $e);
        }

        Http::assertNothingSent();
    }

    public function test_the_api_key_never_appears_in_error_messages(): void
    {
        Http::fake(['*' => Http::response(['status' => false, 'message' => 'Unauthorized'], 401)]);

        try {
            (new LencoService)->verifyPayment('REF-1');
        } catch (LencoApiException $e) {
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
            $this->assertStringNotContainsString(self::KEY, (string) $e);
        }
    }

    // ── Webhook payload parsing ──────────────────────────────────────────────

    public function test_webhook_payload_is_parsed_from_the_data_envelope(): void
    {
        $parsed = (new LencoService)->parseWebhookPayload(['event' => 'collection.successful', 'data' => [
            'id' => 'col-9', 'reference' => 'REF-9', 'lencoReference' => 'LR-9', 'status' => 'successful',
            'amount' => '99.50', 'currency' => 'ZMW', 'completedAt' => '2026-01-01T10:00:00Z',
        ]]);

        $this->assertSame('col-9', $parsed['transactionId']);
        $this->assertSame('REF-9', $parsed['reference']);
        $this->assertSame('LR-9', $parsed['lencoReference']);
        $this->assertSame('successful', $parsed['status']);
        $this->assertSame('99.50', $parsed['amount']);
    }

    public function test_webhook_payload_without_an_envelope_or_fields_does_not_crash(): void
    {
        $parsed = (new LencoService)->parseWebhookPayload([]);

        $this->assertNull($parsed['transactionId']);
        $this->assertNull($parsed['status']);
        $this->assertSame('ZMW', $parsed['currency']);
    }
}
