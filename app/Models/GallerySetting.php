<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GallerySetting extends Model
{
    protected $table = 'gallery_settings';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'id',
        'banner_image',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
        ];
    }
}
