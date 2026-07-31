<?php

namespace App\Services;

use App\Exceptions\LencoApiException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class LencoService
{
    private const BASE_URL_V2 = 'https://api.lenco.co/access/v2';

    private const MAX_RETRIES = 3;

    private const TIMEOUT_SEC = 30;

    private const CONNECT_SEC = 10;

    private string $apiKey;

    private string $webhookSecret;

    public function __construct()
    {
        $this->apiKey = (string) config('services.lenco.api_secret_key', '');
        $this->webhookSecret = (string) config('services.lenco.webhook_secret', '');
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    public function initiateMobileMoneyPayment(array $order, string $phone, string $operator): array
    {
        $reference = $this->generateReference($order['orderNumber']);

        $payload = [
            'phone' => $phone,
            'operator' => strtolower($operator),
            'amount' => (float) $order['totals']['total'],
            'currency' => $order['currency'] ?? 'ZMW',
            'reference' => $reference,
            'country' => $order['country'] ?? 'ZM',
            'description' => 'LFS order '.($order['orderNumber'] ?? ''),
        ];

        $response = $this->request('POST', self::BASE_URL_V2.'/collections/mobile-money', $payload);
        $collection = $response['data'] ?? $response;

        return $this->normalise($collection, $reference);
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyPayment(string $identifier, bool $byReference = true): array
    {
        $path = $byReference
            ? self::BASE_URL_V2.'/collections/status/'.urlencode($identifier)
            : self::BASE_URL_V2.'/collections/'.urlencode($identifier);

        try {
            $response = $this->request('GET', $path);
            $collection = $response['data'] ?? $response;

            return $this->normalise($collection);
        } catch (LencoApiException $e) {
            if ($e->getHttpStatus() === 404 || stripos($e->getMessage(), 'not found') !== false) {
                return ['status' => 'pending', 'internalStatus' => 'pending', 'found' => false];
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    public function verifyWebhookSignature(string $rawBody, array $headers): bool
    {
        if ($this->webhookSecret === '') {
            Log::warning('[LencoService] LENCO_WEBHOOK_SECRET not set — skipping signature check');

            return config('app.env') !== 'production';
        }

        $signature = '';
        $candidates = ['x-lenco-signature', 'x-signature', 'signature', 'authorization'];
        $normalised = [];
        foreach ($headers as $key => $value) {
            $normalised[strtolower((string) $key)] = is_array($value) ? ($value[0] ?? '') : $value;
        }

        foreach ($candidates as $name) {
            if (! empty($normalised[$name])) {
                $signature = (string) $normalised[$name];
                break;
            }
        }

        if ($signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $this->webhookSecret);
        $received = ltrim(preg_replace('/^sha256=/i', '', trim($signature)) ?? '', '');

        $a = str_pad($received, strlen($expected), '0');
        $b = str_pad($expected, strlen($a), '0');

        return hash_equals($b, $a) && strlen($received) === strlen($expected);
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

        if (isset($decoded['status']) && $decoded['status'] === false) {
            throw new LencoApiException($decoded['message'] ?? 'Lenco API error', $httpCode, false, $decoded);
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
        $base = (int) min(1000 * (2 ** $attempt), 10000);
        $jitter = (int) ($base * 0.3 * (mt_rand() / mt_getrandmax()));

        return $base + $jitter;
    }
}
