<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'name',
    'email',
    'password',
    'phone',
    'gender',
    'nationality',
    'satellite_id',
    'town',
    't_shirt_size',
    'avatar_path',
    'registered_at',
    'first_login',
    'must_change_password',
    'temp_password_expires_at',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'satellite_id' => 'integer',
            'registered_at' => 'datetime',
            'first_login' => 'datetime',
            'must_change_password' => 'boolean',
            'temp_password_expires_at' => 'datetime',
        ];
    }

    public function satellite(): BelongsTo
    {
        return $this->belongsTo(Satellite::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function wishlistItems(): HasMany
    {
        return $this->hasMany(WishlistItem::class);
    }

    public function avatarUrl(): ?string
    {
        $path = $this->avatar_path;
        if ($path === null || trim($path) === '') {
            return null;
        }

        $relative = ltrim($path, '/');
        if (! is_file(public_path($relative))) {
            return null;
        }

        return asset($relative);
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim((string) $this->name)) ?: [];
        $letters = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            if ($part !== '') {
                $letters .= mb_strtoupper(mb_substr($part, 0, 1));
            }
        }

        return $letters !== '' ? $letters : 'LF';
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
