<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Blog\ListPublishedPostsRequest;
use App\Http\Resources\BlogPostResource;
use App\Models\BlogPost;

/** Public, read-only blog. Drafts are never reachable here: every query goes through BlogPost::published(). */
class BlogController
{
    public const PER_PAGE = 12;

    public function index(ListPublishedPostsRequest $request)
    {
        return BlogPostResource::paginate(BlogPost::published()->paginate(self::PER_PAGE));
    }

    public function show(string $slug)
    {
        return BlogPostResource::make(BlogPost::published()->where('slug', $slug)->firstOrFail());
    }
}
