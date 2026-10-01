<?php

namespace Tests\Feature\Api;

use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesUsers;
use Tests\Support\RouteDocs;
use Tests\TestCase;

/** SPEC §7 item 8: every admin write and every read of user content leaves an `admin.<route name>` audit row. */
class AdminAuditTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    private function events(User $admin): array
    {
        return DB::table('audit_events')->where('user_id', $admin->id)->orderBy('id')->pluck('event')->all();
    }

    public function test_every_admin_route_goes_through_the_audit_middleware(): void
    {
        $checked = 0;
        foreach (RouteDocs::v1() as $route) {
            if (! str_contains($route->uri(), 'admin/')) {
                continue;
            }
            $this->assertContains('admin.audit', RouteDocs::middleware($route), "{$route->uri()} must be audited");
            $checked++;
        }

        $this->assertGreaterThan(30, $checked);
    }

    public function test_writes_are_recorded_under_the_route_name(): void
    {
        $admin = $this->makeAdmin();
        $member = $this->makeUser();

        $this->signIn($admin)->deleteJson('/api/v1/admin/cache')->assertStatus(204);
        $this->signIn($admin)->patchJson("/api/v1/admin/users/{$member->id}", ['suspended' => true])->assertOk();

        $events = $this->events($admin);
        $this->assertContains('admin.cache.destroy', $events);
        $this->assertContains('admin.users.update', $events);
        $this->assertContains("user_{$member->id}_suspended", $events, 'the controller event is kept');
    }

    public function test_reads_of_user_content_are_recorded_and_config_reads_are_not(): void
    {
        $admin = $this->makeAdmin();
        $member = $this->makeUser();

        foreach (['admin/users', "admin/users/{$member->id}", 'admin/applications', 'admin/contact-messages', 'admin/audit-events'] as $path) {
            $this->signIn($admin)->getJson("/api/v1/{$path}")->assertOk();
        }
        $this->signIn($admin)->getJson('/api/v1/admin/site-settings')->assertOk();
        $this->signIn($admin)->getJson('/api/v1/admin/smtp')->assertOk();

        $events = $this->events($admin);
        foreach (['admin.users.index', 'admin.users.show', 'admin.applications.index', 'admin.contact-messages.index', 'admin.audit-events.index'] as $expected) {
            $this->assertContains($expected, $events);
        }
        $this->assertNotContains('admin.site-settings.show', $events);
        $this->assertNotContains('admin.smtp.show', $events);
    }

    public function test_failed_attempts_are_recorded_as_failed(): void
    {
        $admin = $this->makeAdmin();
        $integration = Integration::create(['provider' => 'openai', 'type' => 'ai', 'secret' => 'sk-test-secret-value', 'model' => 'm', 'enabled' => true, 'priority' => 1]);
        Http::fake(['*' => Http::response(['error' => 'bad key'], 401)]);

        $this->signIn($admin)->postJson("/api/v1/admin/integrations/{$integration->id}/test")->assertStatus(502);
        $this->signIn($admin)->postJson('/api/v1/admin/cv-templates', [])->assertStatus(422);

        $events = $this->events($admin);
        $this->assertContains('admin.integrations.test.failed', $events);
        $this->assertContains('admin.cv-templates.store.failed', $events);
    }

    public function test_cache_clear_and_audit_log_reads_are_recorded(): void
    {
        $admin = $this->makeAdmin();

        $this->signIn($admin)->deleteJson('/api/v1/admin/cache')->assertStatus(204);
        $this->signIn($admin)->getJson('/api/v1/admin/audit-events')->assertOk();

        $events = $this->events($admin);
        $this->assertContains('admin.cache.destroy', $events);
        $this->assertContains('admin.audit-events.index', $events);
    }

    public function test_requests_that_are_not_admin_leave_no_admin_event(): void
    {
        $member = $this->makeUser();

        $this->signIn($member)->deleteJson('/api/v1/admin/cache')->assertStatus(403);

        $this->assertSame([], DB::table('audit_events')->where('event', 'like', 'admin.%')->pluck('event')->all());
    }
}
