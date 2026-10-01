<?php

namespace App\Http\Resources;

/** A blog post as the frontend's `BlogPost` type expects it. The body is plain text, never HTML. */
class BlogPostResource extends ModelResource
{
    protected const FIELDS = ['id', 'title', 'slug', 'excerpt', 'body', 'author_name', 'status', 'published_at', 'updated_at'];
}
