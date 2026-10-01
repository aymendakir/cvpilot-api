<?php

namespace Tests\Feature\Characterization;

use App\Models\CareerReport;
use App\Models\CvDocument;
use App\Models\CvVersion;
use App\Models\Integration;
use App\Models\InterviewSession;
use App\Models\User;
use App\Services\AdminReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** Pins today's admin authorization and the admin data it exposes. */
class AdminAccessTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    /** GET admin routes that work on SQLite. `admin/analytics` uses MySQL-only SQL and is covered in a later slice. */
    private const ADMIN_GETS = [
        'admin/summary', 'admin/users', 'admin/audit-events', 'admin/system', 'admin/site-settings',
        'admin/contact-messages', 'admin/cv-templates', 'admin/smtp', 'admin/integrations', 'admin/applications',
    ];

    public function test_admin_routes_are_401_for_anonymous_403_for_users_200_for_admins(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeAdmin();

        // The test client keeps the seeded session, so check roles in a fixed order.
        foreach (self::ADMIN_GETS as $path) {
            $this->getJson("/api/v1/{$path}")->assertStatus(401);
        }
        foreach (self::ADMIN_GETS as $path) {
            $this->signIn($user)->getJson("/api/v1/{$path}")->assertStatus(403);
        }
        foreach (self::ADMIN_GETS as $path) {
            $this->signIn($admin)->getJson("/api/v1/{$path}")->assertOk();
        }
    }

    public function test_admin_writes_are_403_for_a_normal_user(): void
    {
        $user = $this->makeUser();
        $target = $this->makeUser();

        $this->signIn($user)->patchJson("/api/v1/admin/users/{$target->id}", ['suspended' => true])->assertStatus(403);
        $this->signIn($user)->putJson('/api/v1/admin/site-settings', [])->assertStatus(403);
        $this->signIn($user)->postJson('/api/v1/admin/integrations', [])->assertStatus(403);
        $this->signIn($user)->deleteJson('/api/v1/admin/cache')->assertStatus(403);
        $this->assertFalse($target->refresh()->suspended);
    }

    public function test_users_list_is_paginated_and_searchable(): void
    {
        $admin = $this->makeAdmin();
        $this->makeUser(['name' => 'Findable Person', 'email' => 'findme@example.test']);

        $this->signIn($admin)->getJson('/api/v1/admin/users?search=findme')->assertOk()
            ->assertJsonPath('per_page', 20)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.email', 'findme@example.test');
    }

    public function test_suspending_a_user_revokes_their_sessions_and_audits_it(): void
    {
        $admin = $this->makeAdmin();
        $target = $this->makeUser();

        $this->signIn($admin)->patchJson("/api/v1/admin/users/{$target->id}", ['suspended' => true])
            ->assertOk()->assertJsonPath('suspended', true);

        $target->refresh();
        $this->assertTrue($target->suspended);
        $this->assertSame(2, $target->session_version);
        $this->assertDatabaseHas('audit_events', ['event' => "user_{$target->id}_suspended", 'user_id' => $admin->id]);
    }

    public function test_admin_accounts_cannot_be_suspended(): void
    {
        $admin = $this->makeAdmin();
        $other = $this->makeAdmin();

        $this->signIn($admin)->patchJson("/api/v1/admin/users/{$other->id}", ['suspended' => true])
            ->assertStatus(422)->assertJsonPath('message', 'Admin accounts cannot be suspended here.');
    }

    public function test_user_detail_never_returns_cv_text_to_admins(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeUser();
        $cv = 'PRIVATE CV TEXT '.str_repeat('x', 40);
        CvDocument::create([
            'user_id' => $user->id, 'name' => 'cv.pdf', 'disk_path' => 'cv/1/x.pdf', 'mime' => 'application/pdf',
            'size' => 10, 'extracted_text' => $cv, 'expires_at' => now()->addHours(48),
        ]);
        $version = CvVersion::create(['user_id' => $user->id, 'name' => 'Saved CV', 'content' => $cv, 'source' => 'manual']);
        InterviewSession::create(['user_id' => $user->id, 'title' => 'Dev', 'cv_text' => $cv, 'job_description' => 'job', 'transcript' => [['role' => 'assistant', 'content' => 'Q?']]]);
        CareerReport::create(['user_id' => $user->id, 'type' => 'skill_gap', 'input' => ['cv_text' => $cv], 'output' => 'report text']);
        AdminReview::record('cv', $version);

        $response = $this->signIn($admin)->getJson("/api/v1/admin/users/{$user->id}")->assertOk()
            ->assertJsonStructure(['user', 'counts', 'activity', 'uploads', 'cv_versions', 'applications', 'interviews', 'reports'])
            ->assertJsonPath('uploads.0.name', 'cv.pdf')
            ->assertJsonPath('cv_versions.0.name', 'Saved CV')
            ->assertJsonMissingPath('uploads.0.extracted_text')
            ->assertJsonMissingPath('cv_versions.0.content')
            ->assertJsonMissingPath('interviews.0.cv_text')
            ->assertJsonMissingPath('reports.0.input')
            ->assertJsonMissingPath('reviews');
        $this->assertStringNotContainsString('PRIVATE CV TEXT', $response->getContent());
        $this->assertDatabaseHas('audit_events', ['event' => "user_reviewed:{$user->id}", 'user_id' => $admin->id]);
    }

    public function test_sending_a_warning_mails_the_user_and_returns_201(): void
    {
        $this->fakePlatformMail();
        $admin = $this->makeAdmin();
        $user = $this->makeUser();

        $this->signIn($admin)->postJson("/api/v1/admin/users/{$user->id}/warnings", [
            'subject' => 'Policy reminder', 'message' => 'Please keep uploads professional.', 'severity' => 'notice',
        ])->assertStatus(201)->assertJsonPath('email_sent', true);

        $this->assertCount(1, $this->sentMail);
        $this->assertSame($user->email, $this->sentMail[0]['to']);
    }

    public function test_integration_secrets_are_never_returned_by_list_create_or_update(): void
    {
        $admin = $this->makeAdmin();

        $created = $this->signIn($admin)->postJson('/api/v1/admin/integrations', [
            'provider' => 'openai', 'type' => 'ai', 'secret' => 'sk-super-secret', 'model' => 'gpt-4o-mini', 'enabled' => true,
        ])->assertOk();
        $this->assertStringNotContainsString('sk-super-secret', $created->getContent());

        $list = $this->signIn($admin)->getJson('/api/v1/admin/integrations')->assertOk()
            ->assertJsonStructure(['items', 'catalog', 'job_providers']);
        $this->assertStringNotContainsString('sk-super-secret', $list->getContent());

        $id = Integration::first()->id;
        $updated = $this->signIn($admin)->patchJson("/api/v1/admin/integrations/{$id}", ['model' => 'gpt-4o'])->assertOk();
        $this->assertStringNotContainsString('sk-super-secret', $updated->getContent());
        $this->assertSame('sk-super-secret', Integration::first()->secret, 'stored value decrypts for server-side use');
        $this->assertNotSame('sk-super-secret', \DB::table('integrations')->value('secret'), 'stored encrypted at rest');
    }

    public function test_the_gemini_key_is_sent_in_a_header_not_the_url(): void
    {
        $integration = Integration::create([
            'provider' => 'gemini', 'type' => 'ai', 'secret' => 'GEMINI-KEY-123', 'model' => 'gemini-2.5-flash', 'enabled' => true, 'priority' => 1,
        ]);
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]])]);

        $this->signIn($this->makeAdmin())->postJson("/api/v1/admin/integrations/{$integration->id}/test")->assertOk();

        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'GEMINI-KEY-123')
            && ! str_contains($request->url(), 'key=')
            && $request->header('x-goog-api-key') === ['GEMINI-KEY-123']);
    }

    public function test_integration_test_redacts_secrets_from_the_provider_error(): void
    {
        $integration = Integration::create([
            'provider' => 'gemini', 'type' => 'ai', 'secret' => 'GEMINI-KEY-123', 'model' => 'gemini-2.5-flash', 'enabled' => true, 'priority' => 1,
        ]);
        Http::fake(fn () => throw new ConnectionException(
            'cURL error 28: Operation timed out for https://generativelanguage.googleapis.com/v1beta/models/m:generateContent?key=GEMINI-KEY-123'
        ));

        $response = $this->signIn($this->makeAdmin())->postJson("/api/v1/admin/integrations/{$integration->id}/test")->assertStatus(503);

        // S3 (SPEC decision 12): unreachable / timed out -> 503 with a generic body; the redacted reason is stored.
        $this->assertSame('upstream_unavailable', $response->json('code'));
        $this->assertStringNotContainsString('cURL error 28', $response->getContent());
        $this->assertStringNotContainsString('GEMINI-KEY-123', $response->getContent());
        $this->assertStringContainsString('cURL error 28', (string) $integration->refresh()->last_error);
        $this->assertStringNotContainsString('GEMINI-KEY-123', (string) $integration->last_error);
    }

    public function test_cache_clear_is_a_delete_returning_204(): void
    {
        $this->signIn($this->makeAdmin())->deleteJson('/api/v1/admin/cache')->assertStatus(204);
    }

    public function test_admin_is_identified_by_role_not_email(): void
    {
        $admin = $this->makeAdmin();

        $this->assertSame('admin', User::find($admin->id)->role);
        $this->signIn($admin)->getJson('/api/v1/admin/summary')->assertOk();
    }
}
