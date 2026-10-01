<?php

namespace Tests\Feature\Api;

use App\Http\Resources\BlogPostResource;
use App\Models\BlogPost;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BlogSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function makePost(array $attributes = []): BlogPost
    {
        return BlogPost::create($attributes + [
            'title' => 'Hello', 'slug' => 'hello', 'excerpt' => 'Short', 'body' => str_repeat('Body text. ', 12), 'author_name' => 'Aymen',
        ]);
    }

    public function test_the_table_has_the_specified_columns(): void
    {
        $this->assertSame(
            ['id', 'title', 'slug', 'excerpt', 'body', 'author_name', 'status', 'published_at', 'created_at', 'updated_at'],
            Schema::getColumnListing('blog_posts')
        );
    }

    public function test_a_new_post_is_a_draft_and_slugs_are_unique(): void
    {
        $post = $this->makePost();

        $this->assertSame('draft', $post->refresh()->status);
        $this->assertNull($post->published_at);

        $this->expectException(QueryException::class);
        $this->makePost(['title' => 'Other']);
    }

    public function test_the_resource_exposes_exactly_the_frontend_shape(): void
    {
        $post = $this->makePost(['status' => 'published', 'published_at' => now()]);

        $this->assertSame(
            ['id', 'title', 'slug', 'excerpt', 'body', 'author_name', 'status', 'published_at', 'updated_at'],
            array_keys(BlogPostResource::make($post->refresh())->resolve())
        );
    }

    public function test_the_published_scope_hides_drafts_and_orders_newest_first(): void
    {
        $this->makePost(['slug' => 'draft']);
        $old = $this->makePost(['slug' => 'old', 'status' => 'published', 'published_at' => now()->subDays(3)]);
        $new = $this->makePost(['slug' => 'new', 'status' => 'published', 'published_at' => now()->subDay()]);

        $this->assertSame([$new->id, $old->id], BlogPost::published()->pluck('id')->all());
    }
}
