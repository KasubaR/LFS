<?php

namespace App\Services;

use App\Enums\ApiScope;
use App\Exceptions\CodeException;
use App\Models\ApiClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ApiClientService
{
    public const INVALID_SCOPE_CODE = 'INVALID_API_SCOPE';

    public const CLIENT_NOT_FOUND_CODE = 'API_CLIENT_NOT_FOUND';

    /** Public half of the credential, e.g. lfsk_9f2c1a77b0e4d385. */
    private const KEY_ID_PREFIX = 'lfsk_';

    private const KEY_ID_BYTES = 8;

    private const SECRET_BYTES = 32;

    /**
     * Issue a new client. The plaintext secret is returned exactly once here and
     * is not recoverable afterwards — only its SHA-256 is stored.
     *
     * @param  array{name: string, contactEmail?: ?string, scopes?: list<string>, allowedIps?: list<string>, rateLimitPerMinute?: int, expiresAt?: ?string}  $payload
     * @return array{client: ApiClient, plainToken: string}
     */
    public function create(array $payload): array
    {
        $scopes = ApiScope::onlyValid($payload['scopes'] ?? []);
        if ($scopes === []) {
            throw new CodeException('At least one valid scope is required.', self::INVALID_SCOPE_CODE);
        }

        $keyId = self::KEY_ID_PREFIX.bin2hex(random_bytes(self::KEY_ID_BYTES));
        $secret = bin2hex(random_bytes(self::SECRET_BYTES));

        $client = ApiClient::query()->create([
            'name' => $payload['name'],
            'slug' => $this->uniqueSlug($payload['name']),
            'contact_email' => $payload['contactEmail'] ?? null,
            'key_id' => $keyId,
            'key_hash' => $this->hashSecret($secret),
            'scopes' => $scopes,
            'allowed_ips' => $this->normalizeIps($payload['allowedIps'] ?? []),
            'rate_limit_per_minute' => max(1, (int) ($payload['rateLimitPerMinute'] ?? 60)),
            'expires_at' => $this->parseDate($payload['expiresAt'] ?? null),
        ]);

        return [
            'client' => $client,
            'plainToken' => $keyId.'.'.$secret,
        ];
    }

    /**
     * Resolve a Bearer token to a client. Returns null for any failure so callers
     * cannot distinguish "unknown key" from "wrong secret".
     */
    public function resolveToken(?string $token): ?ApiClient
    {
        if ($token === null || trim($token) === '') {
            return null;
        }

        [$keyId, $secret] = $this->splitToken(trim($token));
        if ($keyId === null || $secret === null) {
            return null;
        }

        $client = ApiClient::query()->where('key_id', $keyId)->first();
        if ($client === null) {
            return null;
        }

        if (! hash_equals($client->key_hash, $this->hashSecret($secret))) {
            return null;
        }

        return $client;
    }

    /**
     * Replace a client's secret in place. Returns the new plaintext token.
     */
    public function rotateSecret(int $clientId): string
    {
        $client = $this->findOrFail($clientId);

        $secret = bin2hex(random_bytes(self::SECRET_BYTES));
        $keyId = self::KEY_ID_PREFIX.bin2hex(random_bytes(self::KEY_ID_BYTES));

        $client->forceFill([
            'key_id' => $keyId,
            'key_hash' => $this->hashSecret($secret),
        ])->save();

        return $keyId.'.'.$secret;
    }

    public function revoke(int $clientId): void
    {
        $client = $this->findOrFail($clientId);

        if ($client->revoked_at === null) {
            $client->forceFill(['revoked_at' => now()])->save();
        }
    }

    public function restore(int $clientId): void
    {
        $this->findOrFail($clientId)->forceFill(['revoked_at' => null])->save();
    }

    /**
     * Touch last_used_at at most once a minute — this runs on every API request
     * and does not warrant a write each time.
     */
    public function touchLastUsed(ApiClient $client): void
    {
        if ($client->last_used_at !== null && $client->last_used_at->diffInSeconds(now()) < 60) {
            return;
        }

        $client->forceFill(['last_used_at' => now()])->saveQuietly();
    }

    private function findOrFail(int $clientId): ApiClient
    {
        $client = ApiClient::query()->find($clientId);

        if ($client === null) {
            throw new CodeException('API client not found.', self::CLIENT_NOT_FOUND_CODE);
        }

        return $client;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function splitToken(string $token): array
    {
        $position = strpos($token, '.');
        if ($position === false || $position === 0 || $position === strlen($token) - 1) {
            return [null, null];
        }

        return [substr($token, 0, $position), substr($token, $position + 1)];
    }

    private function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'client';
        $slug = $base;
        $suffix = 2;

        while (ApiClient::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * @param  list<string>  $ips
     * @return list<string>
     */
    private function normalizeIps(array $ips): array
    {
        return array_values(array_filter(
            array_map(static fn ($ip) => trim((string) $ip), $ips),
            static fn (string $ip) => $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false
        ));
    }

    private function parseDate(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value);
    }
}
