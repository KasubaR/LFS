<?php

namespace App\Models;

use App\Enums\AdminRole;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class AdminUser extends Authenticatable
{
    protected $table = 'admin_users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'last_login_at',
        'totp_secret',
        'totp_confirmed_at',
    ];

    protected $hidden = [
        'password',
        'totp_secret',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'totp_confirmed_at' => 'datetime',
        ];
    }

    public function satellites(): BelongsToMany
    {
        return $this->belongsToMany(Satellite::class, 'admin_user_satellite')
            ->withTimestamps();
    }

    public function roleLabel(): string
    {
        return AdminRole::label((string) $this->role);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === AdminRole::SuperAdmin;
    }

    public function isSatelliteAdministrator(): bool
    {
        return $this->role === AdminRole::SatelliteAdministrator;
    }

    public function isReadOnlyAuditor(): bool
    {
        return $this->role === AdminRole::ReadOnlyAuditor;
    }

    public function requiresTotp(): bool
    {
        // Per the elections brief, every admin role with access to election
        // data needs 2FA — not just the roles that can write to it. Election
        // Observer is read-only but still an elections-facing admin role.
        return in_array($this->role, [
            AdminRole::ElectoralCommission,
            AdminRole::ElectionObserver,
            AdminRole::SuperAdmin,
        ], true);
    }

    public function hasTotpEnabled(): bool
    {
        return $this->totp_secret !== null && $this->totp_confirmed_at !== null;
    }

    /**
     * @return list<int>
     */
    public function satelliteIds(): array
    {
        return $this->satellites->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @return array{id: int, name: string, email: string, role: string, roleLabel: string}
     */
    public function toDisplayArray(): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => (string) $this->role,
            'roleLabel' => $this->roleLabel(),
        ];
    }
}
