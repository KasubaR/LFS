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
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
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
