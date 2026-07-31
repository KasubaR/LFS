<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    protected $table = 'events';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'title',
        'slug',
        'description',
        'location',
        'event_date',
        'distance',
        'recurrence_type',
        'recurrence_days',
        'category',
        'registration_open',
        'registration_close',
        'registration_type',
        'registration_link',
        'banner_image',
        'feature_on_home',
        'brochure_pdf',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'datetime',
            'registration_open' => 'datetime',
            'registration_close' => 'datetime',
            'feature_on_home' => 'boolean',
        ];
    }

    public function distanceRoutes(): HasMany
    {
        return $this->hasMany(EventDistanceRoute::class)->orderBy('sort_order');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(EventResult::class);
    }
}
