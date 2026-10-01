<?php

namespace Tests\Feature\Api;

use App\Models\BlogPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** SPEC §8.1 admin side: what `features/admin/admin-blog.tsx` sends and expects. */
class BlogAdminTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    private function payload(array $override = []): array
    {
        return $override + [
            'title' => 'My first article', 'slug' => 'my-first-article', 'excerpt' => 'A short summary of the article.',
            'body' => str_repeat('Original, useful advice. ', 6), 'author_name' => 'Aymen', 'status' => 'draft',
        ];
    }

    public function test_only_admins_reach_the_blog_admin(): void
    {
        $this->getJson('/api/v1/admin/blog')->assertStatus(401);
        $this->signIn($this->makeUser())->getJson('/api/v1/admin/blog')->assertStatus(403);
        $this->signIn($this->makeUser())->postJson('/api/v1/admin/blog', $this->payload())->assertStatus(403);
        $this->assertDatabaseCount('blog_posts', 0);
    }

    public function test_a_draft_is_created_with_201_and_stays_private(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->signIn($admin)->postJson('/api/v1/admin/blog', $this->payload())->assertStatus(201);

        $response->assertJsonPath('status', 'draft')->assertJsonPath('published_at', null)->assertJsonPath('slug', 'my-first-article');
        $keys = array_keys($response->json());
        sort($keys);
        $this->assertSame(['author_name', 'body', 'excerpt', 'id', 'published_at', 'slug', 'status', 'title', 'updated_at'], $keys);
        $this->getJson('/api/v1/blog/my-first-article')->assertStatus(404);
        $this->getJson('/api/v1/blog')->assertJsonPath('data', []);
    }

    public function test_publishing_sets_published_at_once_and_unpublishing_keeps_it(): void
    {
        $admin = $this->makeAdmin();
        $id = $this->signIn($admin)->postJson('/api/v1/admin/blog', $this->payload())->json('id');

        $first = $this->signIn($admin)->putJson("/api/v1/admin/blog/{$id}", $this->payload(['status' => 'published']))->assertOk();
        $publishedAt = $first->json('published_at');
        $this->assertNotNull($publishedAt);
        $this->getJson('/api/v1/blog/my-first-article')->assertOk();

        $this->travel(2)->hours();
        $this->signIn($admin)->putJson("/api/v1/admin/blog/{$id}", $this->payload(['status' => 'draft', 'title' => 'Renamed']))->assertOk()->assertJsonPath('published_at', $publishedAt);
        $this->getJson('/api/v1/blog/my-first-article')->assertStatus(404);

        $this->signIn($admin)->putJson("/api/v1/admin/blog/{$id}", $this->payload(['status' => 'published']))->assertOk()->assertJsonPath('published_at', $publishedAt);
    }

    public function test_a_post_created_as_published_gets_its_date_immediately(): void
    {
        $response = $this->signIn($this->makeAdmin())->postJson('/api/v1/admin/blog', $this->payload(['status' => 'published']))->assertStatus(201);

        $this->assertNotNull($response->json('published_at'));
        $this->getJson('/api/v1/blog')->assertJsonCount(1, 'data');
    }

    public function test_the_admin_list_shows_drafts_and_pages_like_a_paginator(): void
    {
        $admin = $this->makeAdmin();
        foreach (range(1, 3) as $i) {
            $this->signIn($admin)->postJson('/api/v1/admin/blog', $this->payload(['slug' => "post-{$i}", 'status' => $i === 1 ? 'published' : 'draft']))->assertStatus(201);
        }

        $body = $this->signIn($admin)->getJson('/api/v1/admin/blog')->assertOk()->json();
        $this->assertCount(3, $body['data']);
        $this->assertSame(1, $body['last_page']);
        $this->assertSame(['post-3', 'post-2', 'post-1'], array_column($body['data'], 'slug'));

        $this->signIn($admin)->getJson('/api/v1/admin/blog?per_page=2&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('last_page', 2);
        $this->signIn($admin)->getJson('/api/v1/admin/blog?status=draft')->assertOk()->assertJsonCount(2, 'data');
        $this->signIn($admin)->getJson('/api/v1/admin/blog?status=archived')->assertStatus(422);
    }

    public function test_show_returns_one_post_including_drafts_and_unknown_ids_are_404(): void
    {
        $admin = $this->makeAdmin();
        $id = $this->signIn($admin)->postJson('/api/v1/admin/blog', $this->payload())->json('id');

        $this->signIn($admin)->getJson("/api/v1/admin/blog/{$id}")->assertOk()->assertJsonPath('slug', 'my-first-article');
        $this->signIn($admin)->getJson('/api/v1/admin/blog/99999')->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->signIn($admin)->getJson('/api/v1/admin/blog/abc')->assertStatus(404);
    }

    public function test_update_replaces_the_fields_and_validates_like_create(): void
    {
        $admin = $this->makeAdmin();
        $id = $this->signIn($admin)->postJson('/api/v1/admin/blog', $this->payload())->json('id');

        $this->signIn($admin)->putJson("/api/v1/admin/blog/{$id}", $this->payload(['title' => 'New title', 'slug' => 'new-slug']))
            ->assertOk()->assertJsonPath('title', 'New title')->assertJsonPath('slug', 'new-slug');
        $this->signIn($admin)->putJson("/api/v1/admin/blog/{$id}", [])->assertStatus(422)->assertJsonValidationErrors(['title', 'slug', 'excerpt', 'body', 'author_name', 'status']);
    }

    public function test_delete_is_204_and_the_post_disappears(): void
    {
        $admin = $this->makeAdmin();
        $id = $this->signIn($admin)->postJson('/api/v1/admin/blog', $this->payload(['status' => 'published']))->json('id');

        $this->signIn($admin)->deleteJson("/api/v1/admin/blog/{$id}")->assertStatus(204);

        $this->assertDatabaseCount('blog_posts', 0);
        $this->getJson('/api/v1/blog/my-first-article')->assertStatus(404);
        $this->signIn($admin)->deleteJson("/api/v1/admin/blog/{$id}")->assertStatus(404);
    }

    public function test_duplicate_slugs_are_a_422_on_create_and_update_but_not_against_itself(): void
    {
        $admin = $this->makeAdmin();
        $a = $this->signIn($admin)->postJson('/api/v1/admin/blog', $this->payload(['slug' => 'taken']))->assertStatus(201)->json('id');
        $b = $this->signIn($admin)->postJson('/api/v1/admin/blog', $this->payload(['slug' => 'free']))->json('id');

        $this->signIn($admin)->postJson('/api/v1/admin/blog', $this->payload(['slug' => 'taken']))->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors(['slug']);
        $this->signIn($admin)->putJson("/api/v1/admin/blog/{$b}", $this->payload(['slug' => 'taken']))->assertStatus(422)->assertJsonValidationErrors(['slug']);
        $this->signIn($admin)->putJson("/api/v1/admin/blog/{$a}", $this->payload(['slug' => 'taken', 'title' => 'Edited']))->assertOk();
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidFields(): array
    {
        return [
            'title too long' => [['title' => str_repeat('a', 181)], 'title'],
            'slug uppercase' => [['slug' => 'Not-Valid'], 'slug'],
            'slug spaces' => [['slug' => 'not valid'], 'slug'],
            'slug underscore' => [['slug' => 'not_valid'], 'slug'],
            'slug double dash' => [['slug' => 'not--valid'], 'slug'],
            'slug leading dash' => [['slug' => '-nope'], 'slug'],
            'slug too long' => [['slug' => str_repeat('a', 192)], 'slug'],
            'excerpt too long' => [['excerpt' => str_repeat('a', 501)], 'excerpt'],
            'body too short' => [['body' => 'short'], 'body'],
            'body too long' => [['body' => str_repeat('a', 100001)], 'body'],
            'author too long' => [['author_name' => str_repeat('a', 121)], 'author_name'],
            'unknown status' => [['status' => 'archived'], 'status'],
            'array title' => [['title' => ['x']], 'title'],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_input_is_422_with_the_field_named(array $override, string $field): void
    {
        $this->signIn($this->makeAdmin())->postJson('/api/v1/admin/blog', $this->payload($override))
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors([$field]);

        $this->assertDatabaseCount('blog_posts', 0);
    }

    public function test_the_body_is_stored_and_returned_as_plain_text(): void
    {
        $admin = $this->makeAdmin();
        $html = '<script>alert(1)</script> <b>bold</b> & "quotes" '.str_repeat('x', 80);

        $id = $this->signIn($admin)->postJson('/api/v1/admin/blog', $this->payload(['body' => $html, 'status' => 'published']))->assertStatus(201)->json('id');

        $this->assertSame($html, BlogPost::find($id)->body);
        $response = $this->getJson('/api/v1/blog/my-first-article')->assertOk();
        $this->assertSame($html, $response->json('body'));
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_unknown_fields_cannot_set_dates_or_ids(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->signIn($admin)->postJson('/api/v1/admin/blog', $this->payload(['id' => 4242, 'published_at' => '2001-01-01 00:00:00', 'created_at' => '2001-01-01 00:00:00']))->assertStatus(201);

        $this->assertNotSame(4242, $response->json('id'));
        $this->assertNull($response->json('published_at'));
    }

    public function test_every_blog_write_is_audited(): void
    {
        $admin = $this->makeAdmin();
        $id = $this->signIn($admin)->postJson('/api/v1/admin/blog', $this->payload())->json('id');
        $this->signIn($admin)->putJson("/api/v1/admin/blog/{$id}", $this->payload(['title' => 'Edited']))->assertOk();
        $this->signIn($admin)->deleteJson("/api/v1/admin/blog/{$id}")->assertStatus(204);

        $events = DB::table('audit_events')->where('user_id', $admin->id)->pluck('event')->all();
        foreach (['admin.blog.store', 'admin.blog.update', 'admin.blog.destroy'] as $event) {
            $this->assertContains($event, $events);
        }
    }

    public function test_a_slug_race_lost_at_the_database_is_still_a_422(): void
    {
        $admin = $this->makeAdmin();
        // Another save wins between validation and insert.
        BlogPost::creating(function (BlogPost $post) {
            DB::table('blog_posts')->insert(['title' => 'Winner', 'slug' => $post->slug, 'excerpt' => 'x', 'body' => 'x', 'author_name' => 'x', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        });

        $this->signIn($admin)->postJson('/api/v1/admin/blog', $this->payload(['slug' => 'raced']))
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors(['slug']);
        $this->assertDatabaseCount('blog_posts', 1);
    }
}
