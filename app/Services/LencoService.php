<?php

namespace App\Services;

use App\Exceptions\LencoApiException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class LencoService
{
    private const MAX_RETRIES = 3;

    private const TIMEOUT_SEC = 30;

    private const CONNECT_SEC = 10;

    private string $apiKey;

    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.lenco.base_url'), '/');
        $this->apiKey = (string) config('services.lenco.api_secret_key', '');
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    /**
     * @param  string|null  $reference  caller-supplied reference. Ticket orders
     *                                  generate and save it BEFORE this call so a
     *                                  charge can always be found again by
     *                                  reference even if saving the response fails.
     */
    public function initiateMobileMoneyPayment(array $order, string $phone, string $operator, ?string $reference = null): array
    {
        $reference ??= $this->generateReference($order['orderNumber']);

        $payload = [
            'phone' => $phone,
            'operator' => strtolower($operator),
            'amount' => (float) $order['totals']['total'],
            'currency' => $order['currency'] ?? 'ZMW',
            'reference' => $reference,
            'country' => $order['country'] ?? 'ZM',
            'description' => $order['description'] ?? 'LFS order '.($order['orderNumber'] ?? ''),
        ];

        $response = $this->request('POST', $this->baseUrl.'/collections/mobile-money', $payload);
        $collection = $response['data'] ?? $response;

        return $this->normalise($collection, $reference);
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyPayment(string $identifier, bool $byReference = true): array
    {
        $path = $byReference
            ? $this->baseUrl.'/collections/status/'.urlencode($identifier)
            : $this->baseUrl.'/collections/'.urlencode($identifier);

        try {
            $response = $this->request('GET', $path);
            $collection = $response['data'] ?? $response;

            return $this->normalise($collection);
        } catch (LencoApiException $e) {
            // Only a real 404 means "Lenco has no such collection". Matching on the
            // message text would hide genuine errors that happen to contain those words.
            if ($e->getHttpStatus() === 404) {
                return ['status' => 'pending', 'internalStatus' => 'pending', 'found' => false];
            }

            throw $e;
        }
    }

    /**
     * Verifies a Lenco webhook (https://lenco-api.readme.io/v2.0/reference/webhooks).
     *
     * Lenco signs the raw request body with HMAC-SHA512 and sends it in the
     * X-Lenco-Signature header. The signing key (the "webhook hash key") is the
     * SHA-256 hash of the API secret key. Fails closed: with no API key
     * configured, or no signature, nothing is accepted — in every environment.
     *
     * @param  array<string, mixed>  $headers
     */
    public function verifyWebhookSignature(string $rawBody, array $headers): bool
    {
        if ($this->apiKey === '') {
            Log::error('[LencoService] LENCO_API_SECRET_KEY not set — rejecting webhook');

            return false;
        }

        $signature = '';
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === 'x-lenco-signature') {
                $signature = trim((string) (is_array($value) ? ($value[0] ?? '') : $value));
                break;
            }
        }

        if ($signature === '') {
            return false;
        }

        $hashKey = hash('sha256', $this->apiKey);
        $expected = hash_hmac('sha512', $rawBody, $hashKey);

        return hash_equals($expected, strtolower($signature));
    }

    /**
     * Turns a Lenco/PHP failure into text that is safe to show a customer.
     * Raw provider or exception messages are never returned.
     */
    public function customerMessage(\Throwable $e): string
    {
        $generic = 'We could not start your payment right now. Please try again in a moment.';

        if (! $e instanceof LencoApiException) {
            return $generic;
        }

        $status = $e->getHttpStatus();
        $text = strtolower($e->getMessage());

        if (str_contains($text, 'phone') || str_contains($text, 'msisdn') || str_contains($text, 'operator')) {
            return 'Please check your mobile money number and network, then try again.';
        }
        if (str_contains($text, 'insufficient')) {
            return 'Your mobile money account has insufficient funds for this payment.';
        }
        if (str_contains($text, 'duplicate') || str_contains($text, 'already')) {
            return 'This payment was already submitted. Please wait a minute and check your phone.';
        }
        if ($status === 429 || $status >= 500 || $e->isRetryable()) {
            return 'The payment provider is busy right now. Please try again in a few minutes.';
        }
        if ($status === 400 || $status === 422) {
            return 'The payment details were not accepted. Please check them and try again.';
        }

        return $generic;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function parseWebhookPayload(array $raw): array
    {
        $data = $raw['data'] ?? $raw;

        return [
            'transactionId' => $data['id'] ?? $data['transactionId'] ?? null,
            'reference' => $data['reference'] ?? null,
            'lencoReference' => $data['lencoReference'] ?? null,
            'orderNumber' => $data['orderNumber'] ?? $data['metadata']['orderNumber'] ?? null,
            'status' => $data['status'] ?? null,
            'amount' => $data['amount'] ?? null,
            'currency' => $data['currency'] ?? 'ZMW',
            'completedAt' => $data['completedAt'] ?? null,
            'failedAt' => isset($data['reasonForFailure']) ? date('c') : null,
            'failureReason' => $data['reasonForFailure'] ?? $data['failureReason'] ?? null,
        ];
    }

    public function mapLencoStatus(string $lencoStatus): string
    {
        return match (strtolower($lencoStatus)) {
            'successful', 'success', 'completed' => 'completed',
            'failed' => 'failed',
            'cancelled', 'expired' => 'cancelled',
            'processing' => 'processing',
            default => 'pending',
        };
    }

    public function generateReference(string $orderNumber): string
    {
        $timestamp = (int) (microtime(true) * 1000);
        $random = bin2hex(random_bytes(4));

        return strtoupper("LFS-{$orderNumber}-{$timestamp}-{$random}");
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, array $body = [], int $attempt = 0): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException(
                'LENCO_API_SECRET_KEY environment variable is not set. Set it in your .env to enable payments.'
            );
        }

        try {
            $pending = Http::withToken($this->apiKey)
                ->acceptJson()
                ->asJson()
                ->connectTimeout(self::CONNECT_SEC)
                ->timeout(self::TIMEOUT_SEC);

            $response = $method === 'POST'
                ? $pending->post($url, $body)
                : $pending->get($url);
        } catch (\Throwable $e) {
            if ($attempt < self::MAX_RETRIES) {
                usleep($this->retryDelay($attempt) * 1000);

                return $this->request($method, $url, $body, $attempt + 1);
            }

            throw new LencoApiException('HTTP error: '.$e->getMessage(), 0, true, [], $e);
        }

        $httpCode = $response->status();
        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new LencoApiException('Invalid JSON response from Lenco', $httpCode, false);
        }

        if ($httpCode >= 400) {
            $retryable = $httpCode >= 500 || in_array($httpCode, [408, 429], true);
            if ($retryable && $attempt < self::MAX_RETRIES) {
                usleep($this->retryDelay($attempt) * 1000);

                return $this->request($method, $url, $body, $attempt + 1);
            }

            throw new LencoApiException(
                $decoded['message'] ?? 'Lenco API error',
                $httpCode,
                $retryable,
                $decoded
            );
        }

        // Lenco also answers HTTP 200 with {"status": false, ...} for rejected requests.
        // Checked AFTER the HTTP code so a 429/5xx whose body carries status:false is
        // still retried instead of being treated as a final refusal.
        if (isset($decoded['status']) && $decoded['status'] === false) {
            throw new LencoApiException($decoded['message'] ?? 'Lenco API error', $httpCode, false, $decoded);
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $col
     * @return array<string, mixed>
     */
    private function normalise(array $col, ?string $fallbackRef = null): array
    {
        $status = $col['status'] ?? 'pending';

        return [
            'transactionId' => $col['id'] ?? $col['transactionId'] ?? null,
            'lencoReference' => $col['lencoReference'] ?? null,
            'reference' => $col['reference'] ?? $fallbackRef,
            'status' => $status,
            'internalStatus' => $this->mapLencoStatus($status),
            'amount' => $col['amount'] ?? null,
            'currency' => $col['currency'] ?? 'ZMW',
            'paymentInstructions' => $col['paymentInstructions'] ?? null,
            'paymentUrl' => $col['paymentUrl'] ?? null,
            'expiresAt' => $col['expiresAt'] ?? null,
            'failureReason' => $col['reasonForFailure'] ?? $col['failureReason'] ?? null,
            'rawResponse' => $col,
        ];
    }

    private function retryDelay(int $attempt): int
    {
        $unit = max(1, (int) config('services.lenco.retry_base_ms', 1000));
        $base = (int) min($unit * (2 ** $attempt), $unit * 10);
        $jitter = (int) ($base * 0.3 * (mt_rand() / mt_getrandmax()));

        return $base + $jitter;
    }
}
