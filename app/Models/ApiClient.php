<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApiClient extends Model
{
    protected $table = 'api_clients';

    protected $fillable = [
        'name',
        'slug',
        'contact_email',
        'key_id',
        'key_hash',
        'scopes',
        'allowed_ips',
        'rate_limit_per_minute',
        'last_used_at',
        'expires_at',
        'revoked_at',
    ];

    protected $hidden = [
        'key_hash',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'allowed_ips' => 'array',
            'rate_limit_per_minute' => 'integer',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function requestLogs(): HasMany
    {
        return $this->hasMany(ApiRequestLog::class, 'api_client_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    /**
     * An empty/absent allowlist means "any IP".
     */
    public function allowsIp(?string $ip): bool
    {
        $allowed = $this->allowed_ips ?? [];

        if ($allowed === []) {
            return true;
        }

        return $ip !== null && in_array($ip, $allowed, true);
    }

    public function statusLabel(): string
    {
        if ($this->isRevoked()) {
            return 'Revoked';
        }

        if ($this->isExpired()) {
            return 'Expired';
        }

        return 'Active';
    }
}
