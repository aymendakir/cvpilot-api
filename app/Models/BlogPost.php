<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BlogPost extends Model
{
    public const STATUSES = ['draft', 'published'];

    /** Lowercase words joined by single hyphens (also the route constraint of `blog/{slug}`). */
    public const SLUG_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';

    protected $guarded = [];

    protected $casts = ['published_at' => 'datetime'];

    /** Posts the public can read, newest first. The only way public routes may query posts. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')->orderByDesc('published_at')->orderByDesc('id');
    }
}
