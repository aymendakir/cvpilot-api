<?php

namespace Tests\Feature\Characterization;

use App\Models\CareerReport;
use App\Models\CvVersion;
use App\Models\InterviewSession;
use App\Models\JobWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** Pins today's /api/career/* behavior that does not call an AI provider. */
class CareerTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    private string $cvText;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cvText = str_repeat('Experienced developer. ', 5);
    }

    public function test_career_routes_require_a_session(): void
    {
        foreach (['me/dashboard', 'library', 'cv-versions', 'job-workspaces', 'me/analytics'] as $path) {
            $this->getJson("/api/v1/{$path}")->assertStatus(401);
        }
    }

    public function test_cv_version_crud(): void
    {
        $me = $this->makeUser();

        $id = $this->signIn($me)->postJson('/api/v1/cv-versions', [
            'name' => 'My CV', 'content' => $this->cvText, 'source' => 'manual', 'builder_data' => ['template' => 'modern'],
        ])->assertStatus(201)->assertJsonPath('name', 'My CV')->json('id');

        $this->signIn($me)->getJson('/api/v1/cv-versions')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.id', $id);

        $this->signIn($me)->getJson("/api/v1/cv-versions/{$id}")->assertOk()
            ->assertJsonPath('builder_data.template', 'modern');

        $this->signIn($me)->patchJson("/api/v1/cv-versions/{$id}", ['name' => 'Renamed', 'content' => $this->cvText])
            ->assertOk()->assertJsonPath('name', 'Renamed');

        $this->signIn($me)->deleteJson("/api/v1/cv-versions/{$id}")->assertStatus(204);
        $this->assertDatabaseMissing('cv_versions', ['id' => $id]);
    }

    public function test_cv_version_validation_is_422(): void
    {
        $this->signIn($this->makeUser())->postJson('/api/v1/cv-versions', ['name' => '', 'content' => 'too short', 'source' => 'scan'])
            ->assertStatus(422)->assertJsonStructure(['message', 'errors' => ['name', 'content', 'source']]);
    }

    public function test_cv_versions_are_private_to_their_owner(): void
    {
        $owner = $this->makeUser();
        $version = CvVersion::create(['user_id' => $owner->id, 'name' => 'Private', 'content' => $this->cvText]);
        $intruder = $this->makeUser();

        $this->signIn($intruder)->getJson("/api/v1/cv-versions/{$version->id}")->assertStatus(404);
        $this->signIn($intruder)->patchJson("/api/v1/cv-versions/{$version->id}", ['name' => 'x', 'content' => $this->cvText])->assertStatus(404);
        $this->signIn($intruder)->deleteJson("/api/v1/cv-versions/{$version->id}")->assertStatus(404);
        $this->signIn($intruder)->getJson('/api/v1/cv-versions')->assertOk()->assertJsonCount(0);
    }

    public function test_workspace_create_and_list(): void
    {
        $me = $this->makeUser();

        $this->signIn($me)->postJson('/api/v1/job-workspaces', [
            'title' => 'Dev at Acme', 'company' => 'Acme', 'job_url' => 'https://example.test/job',
            'job_description' => str_repeat('We need a developer. ', 5), 'cv_text' => $this->cvText,
        ])->assertStatus(201)->assertJsonPath('title', 'Dev at Acme');

        $this->signIn($me)->getJson('/api/v1/job-workspaces')->assertOk()->assertJsonCount(1);
    }

    public function test_workspace_validation_is_422(): void
    {
        $this->signIn($this->makeUser())->postJson('/api/v1/job-workspaces', [
            'title' => 'x', 'job_url' => 'http://insecure.test', 'job_description' => 'short', 'cv_text' => 'short',
        ])->assertStatus(422)->assertJsonStructure(['message', 'errors' => ['job_url', 'job_description', 'cv_text']]);
    }

    public function test_workspace_of_another_user_is_404(): void
    {
        $workspace = JobWorkspace::create([
            'user_id' => $this->makeUser()->id, 'title' => 'Theirs', 'job_description' => str_repeat('a ', 40), 'cv_text' => $this->cvText,
        ]);

        $this->signIn($this->makeUser())->getJson("/api/v1/job-workspaces/{$workspace->id}")->assertStatus(404);
    }

    public function test_interview_and_report_ownership_and_deletion(): void
    {
        $owner = $this->makeUser();
        $session = InterviewSession::create([
            'user_id' => $owner->id, 'title' => 'Mock', 'cv_text' => $this->cvText, 'job_description' => 'Job', 'transcript' => [],
        ]);
        $report = CareerReport::create(['user_id' => $owner->id, 'type' => 'skill_gap', 'output' => 'Report body']);
        $intruder = $this->makeUser();

        $this->signIn($intruder)->getJson("/api/v1/interviews/{$session->id}")->assertStatus(404);
        $this->signIn($intruder)->deleteJson("/api/v1/reports/{$report->id}")->assertStatus(404);

        $this->signIn($owner)->getJson("/api/v1/interviews/{$session->id}")->assertOk()->assertJsonPath('title', 'Mock');
        $this->signIn($owner)->deleteJson("/api/v1/interviews/{$session->id}")->assertStatus(204);
        $this->signIn($owner)->deleteJson("/api/v1/reports/{$report->id}")->assertStatus(204);
    }

    public function test_library_returns_counts_and_a_paginator_defaulting_to_cvs(): void
    {
        $me = $this->makeUser();
        CvVersion::create(['user_id' => $me->id, 'name' => 'A', 'content' => $this->cvText]);
        CareerReport::create(['user_id' => $me->id, 'type' => 'cover_letter', 'output' => 'Dear team']);

        $this->signIn($me)->getJson('/api/v1/library')->assertOk()
            ->assertJsonPath('counts.cv', 1)
            ->assertJsonPath('counts.cover_letter', 1)
            ->assertJsonPath('counts.upload', 0)
            ->assertJsonPath('items.per_page', 12)
            ->assertJsonPath('items.total', 1);

        $this->signIn($me)->getJson('/api/v1/library?kind=cover_letter')->assertOk()->assertJsonPath('items.total', 1);
    }

    public function test_library_rejects_an_unknown_kind_with_422(): void
    {
        $this->signIn($this->makeUser())->getJson('/api/v1/library?kind=secrets')->assertStatus(422);
    }

    public function test_dashboard_and_analytics_shapes(): void
    {
        $me = $this->makeUser();

        $this->signIn($me)->getJson('/api/v1/me/dashboard')->assertOk()->assertJsonStructure([
            'applications', 'cv_versions', 'cv_documents', 'cover_letters', 'interviews', 'workspaces', 'statuses', 'reminders',
        ]);

        $this->signIn($me)->getJson('/api/v1/me/analytics')->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('interview_rate', 0)
            ->assertJsonStructure(['total', 'by_status', 'interview_rate', 'offer_rate']);
    }

    public function test_diagnostic_needs_three_applications_before_calling_ai(): void
    {
        $this->signIn($this->makeUser())->postJson('/api/v1/ai/career-diagnostic')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Add at least 3 applications before running this diagnosis.');
    }
}
