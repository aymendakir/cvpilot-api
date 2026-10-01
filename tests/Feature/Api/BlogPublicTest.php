<?php

namespace Tests\Feature\Api;

use App\Models\BlogPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** SPEC §8.1: the public blog API the frontend (blog pages, home page, sitemap) reads. */
class BlogPublicTest extends TestCase
{
    use RefreshDatabase;

    private function makePost(array $attributes = []): BlogPost
    {
        static $n = 0;
        $n++;

        return BlogPost::create($attributes + [
            'title' => "Post {$n}", 'slug' => "post-{$n}", 'excerpt' => 'Short summary', 'body' => str_repeat('Body text. ', 12),
            'author_name' => 'Aymen', 'status' => 'published', 'published_at' => now()->subMinutes(100 - $n),
        ]);
    }

    public function test_the_list_has_only_published_posts_newest_first_in_the_frontend_shape(): void
    {
        $old = $this->makePost(['slug' => 'old', 'published_at' => now()->subDays(5)]);
        $this->makePost(['slug' => 'secret-draft', 'status' => 'draft', 'published_at' => null]);
        $new = $this->makePost(['slug' => 'new', 'published_at' => now()->subDay()]);

        $body = $this->getJson('/api/v1/blog')->assertOk()->json();

        $this->assertSame([$new->id, $old->id], array_column($body['data'], 'id'));
        $this->assertSame(['id', 'title', 'slug', 'excerpt', 'body', 'author_name', 'status', 'published_at', 'updated_at'], array_keys($body['data'][0]));
        foreach (['data', 'current_page', 'last_page', 'per_page', 'total'] as $key) {
            $this->assertArrayHasKey($key, $body);
        }
        $this->assertSame(12, $body['per_page']);
        $this->assertStringNotContainsString('secret-draft', json_encode($body));
    }

    public function test_pages_hold_twelve_posts_and_last_page_is_at_least_one(): void
    {
        $this->getJson('/api/v1/blog')->assertOk()->assertJsonPath('last_page', 1)->assertJsonPath('data', []);

        foreach (range(1, 13) as $i) {
            $this->makePost();
        }

        $this->getJson('/api/v1/blog')->assertOk()->assertJsonCount(12, 'data')->assertJsonPath('last_page', 2);
        $this->getJson('/api/v1/blog?page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('current_page', 2);
    }

    public function test_an_invalid_page_is_a_validation_error(): void
    {
        $this->getJson('/api/v1/blog?page=0')->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->getJson('/api/v1/blog?page=abc')->assertStatus(422);
    }

    public function test_a_published_post_is_readable_by_slug_and_drafts_are_404(): void
    {
        $post = $this->makePost(['slug' => 'hello-world']);
        $this->makePost(['slug' => 'not-yet', 'status' => 'draft', 'published_at' => null]);

        $this->getJson('/api/v1/blog/hello-world')->assertOk()->assertJsonPath('id', $post->id)->assertJsonPath('body', $post->body);
        $this->getJson('/api/v1/blog/not-yet')->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->getJson('/api/v1/blog/missing')->assertStatus(404)->assertJsonPath('code', 'not_found');
    }

    public function test_slugs_outside_the_pattern_are_404(): void
    {
        $this->makePost(['slug' => 'ok-slug']);

        foreach (['UPPER', 'under_score', 'double--dash', '-lead', 'trail-', 'a%2Fb'] as $slug) {
            $this->getJson("/api/v1/blog/{$slug}")->assertStatus(404);
        }
    }

    public function test_public_blog_responses_are_cacheable_and_cookie_free(): void
    {
        $this->makePost(['slug' => 'cache-me']);

        foreach (['/api/v1/blog', '/api/v1/blog/cache-me', '/api/v1/blog', '/api/v1/blog/cache-me'] as $path) {
            $response = $this->getJson($path)->assertOk();

            $cache = (string) $response->headers->get('Cache-Control');
            $this->assertStringContainsString('public', $cache, $path);
            $this->assertStringContainsString('max-age=60', $cache, $path);
            $this->assertStringNotContainsString('no-store', $cache, $path);
            $this->assertCount(0, $response->headers->getCookies(), "{$path} must not set cookies");
            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $response->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        }
    }

    public function test_errors_and_other_routes_stay_no_store(): void
    {
        $this->assertStringContainsString('no-store', (string) $this->getJson('/api/v1/blog/missing')->assertStatus(404)->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $this->getJson('/api/v1/blog?page=0')->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $this->getJson('/api/v1/site-settings')->headers->get('Cache-Control'));
    }

    public function test_the_blog_is_throttled_per_ip(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/v1/blog')->assertOk();
        }

        $response = $this->getJson('/api/v1/blog')->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    public function test_the_sitemap_walk_over_last_page_terminates_with_every_published_post(): void
    {
        foreach (range(1, 25) as $i) {
            $this->makePost();
        }
        $this->makePost(['status' => 'draft', 'published_at' => null]);

        $seen = [];
        $page = 1;
        do {
            $data = $this->getJson("/api/v1/blog?page={$page}")->assertOk()->json();
            $seen = array_merge($seen, array_column($data['data'], 'slug'));
            $page++;
        } while ($page <= $data['last_page'] && $page <= 100);

        $this->assertCount(25, array_unique($seen));
    }
}
