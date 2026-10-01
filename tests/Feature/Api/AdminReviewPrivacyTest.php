<?php

namespace Tests\Feature\Api;

use App\Models\AdminReviewItem;
use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** S4: admins no longer get temporary copies of user CV text in admin_review_items. */
class AdminReviewPrivacyTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    private const MARKER = 'PRIVATE-CV-MARKER-xyz';

    private function cv(): string
    {
        return self::MARKER.' Experienced developer with PHP, Laravel and MySQL experience. Built APIs.';
    }

    private const JOB = 'We are hiring a backend developer to build and maintain Laravel APIs, write tests and review code with the team.';

    private function assertNoCvCopy(): void
    {
        $this->assertSame([], AdminReviewItem::whereIn('kind', ['cv', 'workspace', 'interview', 'report'])->pluck('kind')->all());
        foreach (AdminReviewItem::all() as $item) {
            $this->assertStringNotContainsString(self::MARKER, json_encode($item->payload), "{$item->kind} copy holds CV text");
        }
        $this->assertStringNotContainsString(self::MARKER, json_encode(DB::table('admin_review_items')->get()));
    }

    public function test_saving_cv_versions_and_workspaces_writes_no_admin_copy(): void
    {
        $user = $this->makeUser();

        $id = $this->signIn($user)->postJson('/api/v1/cv-versions', ['name' => 'CV', 'content' => $this->cv(), 'source' => 'manual'])->assertStatus(201)->json('id');
        $this->signIn($user)->patchJson("/api/v1/cv-versions/{$id}", ['name' => 'CV 2', 'content' => $this->cv()])->assertOk();
        $this->signIn($user)->postJson('/api/v1/job-workspaces', ['title' => 'Dev', 'job_description' => self::JOB, 'cv_text' => $this->cv()])->assertStatus(201);
        $this->signIn($user)->deleteJson("/api/v1/cv-versions/{$id}")->assertStatus(204);

        $this->assertNoCvCopy();
    }

    public function test_interviews_and_reports_write_no_admin_copy(): void
    {
        $user = $this->makeUser();
        Integration::create(['provider' => 'openai', 'type' => 'ai', 'secret' => 'sk-test-secret-value', 'model' => 'm', 'enabled' => true, 'priority' => 1]);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'Generated answer']]]])]);
        $docs = ['cv_text' => $this->cv(), 'job_description' => self::JOB, 'title' => 'Dev', 'company' => 'Acme'];

        $session = $this->signIn($user)->postJson('/api/v1/interviews', $docs)->assertOk()->json('session_id');
        $this->signIn($user)->postJson("/api/v1/interviews/{$session}/reply", ['answer' => 'I built APIs.'])->assertOk();
        $this->signIn($user)->postJson("/api/v1/interviews/{$session}/finish")->assertOk();
        $this->signIn($user)->postJson('/api/v1/ai/tailor-cv', $docs)->assertOk();
        $this->signIn($user)->postJson('/api/v1/ai/recruiter-view', $docs)->assertOk();
        $this->signIn($user)->postJson('/api/v1/ai/cover-letter', ['cv_text' => $this->cv(), 'job_description' => self::JOB, 'title' => 'Dev', 'company' => 'Acme'])->assertOk();

        $this->assertDatabaseHas('interview_sessions', ['id' => $session]);
        $this->assertDatabaseHas('career_reports', ['type' => 'tailor_cv']);
        $this->assertNoCvCopy();
    }

    public function test_applications_are_still_copied_for_the_admin_list(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeAdmin();

        $this->signIn($user)->postJson('/api/v1/applications', ['title' => 'Dev', 'company' => 'Acme', 'url' => 'https://example.com/job'])->assertStatus(201);

        $this->assertSame(['application'], AdminReviewItem::pluck('kind')->all());
        $this->signIn($admin)->getJson('/api/v1/admin/applications')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_the_purge_migration_deletes_only_the_cv_bearing_copies(): void
    {
        $user = $this->makeUser();
        foreach (['cv', 'workspace', 'interview', 'report', 'application', 'application'] as $i => $kind) {
            AdminReviewItem::create(['user_id' => $user->id, 'kind' => $kind, 'record_id' => $i + 1, 'payload' => ['cv_text' => self::MARKER], 'expires_at' => now()->addHours(48)]);
        }

        (require database_path('migrations/2026_10_02_000009_purge_admin_review_cv_copies.php'))->up();

        $this->assertSame(['application', 'application'], AdminReviewItem::orderBy('id')->pluck('kind')->all());
    }
}
