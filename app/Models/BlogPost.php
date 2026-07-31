<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BlogPost extends Model
{
    protected $table = 'blog_posts';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'title',
        'slug',
        'excerpt',
        'content',
        'featured_image',
        'author',
        'category',
        'tags',
        'status',
        'featured',
        'views',
        'publish_date',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'featured' => 'boolean',
            'views' => 'integer',
            'publish_date' => 'datetime',
        ];
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }
}
