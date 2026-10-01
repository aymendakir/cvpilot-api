<?php

namespace Tests\Feature\Characterization;

use App\Models\CvTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** Pins today's unauthenticated endpoints and the endpoints the frontend calls that do not exist yet. */
class PublicEndpointsTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    public function test_site_settings_are_public_and_return_the_defaults(): void
    {
        $this->getJson('/api/site-settings')->assertOk()->assertJsonStructure([
            'brand_name', 'site_url', 'default_title', 'default_description', 'support_email',
            'publisher_name', 'google_verification', 'adsense_publisher_id', 'indexing_enabled', 'pages',
        ]);
    }

    public function test_contact_creates_a_message_and_returns_a_reference(): void
    {
        $response = $this->postJson('/api/contact', [
            'name' => 'Visitor', 'email' => 'visitor@example.test', 'topic' => 'technical',
            'message' => 'The upload button does not respond on my phone.',
        ])->assertStatus(201)->assertJsonStructure(['message', 'reference']);

        $this->assertMatchesRegularExpression('/^CVP-\d+$/', $response->json('reference'));
        $this->assertDatabaseHas('support_messages', ['email' => 'visitor@example.test', 'status' => 'new']);
    }

    public function test_contact_validation_and_honeypot_are_422(): void
    {
        $this->postJson('/api/contact', ['name' => '', 'email' => 'bad', 'topic' => 'sales', 'message' => 'short'])
            ->assertStatus(422)->assertJsonStructure(['message', 'errors' => ['name', 'email', 'topic', 'message']]);

        $this->postJson('/api/contact', [
            'name' => 'Bot', 'email' => 'bot@example.test', 'topic' => 'feedback',
            'message' => 'Buy cheap things at my website now please.', 'website' => 'https://spam.example',
        ])->assertStatus(422);
        $this->assertDatabaseCount('support_messages', 0);
    }

    public function test_contact_is_throttled_to_three_per_window(): void
    {
        $payload = ['name' => 'V', 'email' => 'v@example.test', 'topic' => 'feedback', 'message' => 'A sufficiently long message.'];

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/contact', $payload)->assertStatus(201);
        }
        $this->postJson('/api/contact', $payload)->assertStatus(429);
    }

    public function test_only_published_cv_templates_are_public(): void
    {
        $design = ['layout' => 'modern', 'accent' => '#336699', 'font' => 'sans', 'spacing' => 'comfortable'];
        CvTemplate::create(['name' => 'Live', 'published' => true, 'design' => $design, 'sample' => ['name' => 'A']]);
        CvTemplate::create(['name' => 'Draft', 'published' => false, 'design' => $design, 'sample' => ['name' => 'B']]);

        $this->getJson('/api/cv-templates')->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'Live');
    }

    public function test_analytics_events_require_consent_and_store_hashed_ids(): void
    {
        $this->postJson('/api/analytics/events', [
            'visitor_id' => str_repeat('a', 20), 'session_id' => str_repeat('b', 20), 'path' => '/',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['consent']]);

        $this->postJson('/api/analytics/events', [
            'consent' => true, 'visitor_id' => str_repeat('a', 20), 'session_id' => str_repeat('b', 20),
            'path' => '/ats-checker?x=1#top', 'referrer' => 'https://example.org/page',
        ])->assertStatus(201)->assertExactJson(['recorded' => true]);

        $event = \DB::table('traffic_events')->first();
        $this->assertSame('/ats-checker', $event->path);
        $this->assertSame('example.org', $event->referrer_host);
        $this->assertNotSame(str_repeat('a', 20), $event->visitor_id, 'visitor id is stored hashed');
    }

    public function test_the_blog_api_the_frontend_calls_exists(): void
    {
        // S5 (SPEC §8.1): flipped from a GAP. Detailed behaviour lives in BlogPublicTest and BlogAdminTest.
        $this->getJson('/api/blog')->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/blog/some-post')->assertStatus(404);
        $this->signIn($this->makeAdmin())->getJson('/api/admin/blog')->assertOk();
    }

    public function test_admin_user_creation_the_frontend_calls_exists(): void
    {
        // S5 (SPEC §8.2): flipped from a GAP (405). Full behaviour lives in AdminCreateUserTest.
        $this->signIn($this->makeAdmin())->postJson('/api/admin/users', ['name' => 'x'])->assertStatus(422);
    }

    public function test_the_root_serves_the_legacy_console_html(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_the_health_route_is_up(): void
    {
        $this->get('/up')->assertOk();
    }
}
