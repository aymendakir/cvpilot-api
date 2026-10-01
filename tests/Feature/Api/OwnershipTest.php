<?php

namespace Tests\Feature\Api;

use App\Models\Application;
use App\Models\CareerReport;
use App\Models\CvDocument;
use App\Models\CvVersion;
use App\Models\InterviewSession;
use App\Models\JobSearch;
use App\Models\JobWorkspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Owner-only records: another user's id is a plain 404 (never 403, never the data).
 * The record must be untouched afterwards.
 */
class OwnershipTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    private const CV = 'Experienced developer with PHP, Laravel and MySQL experience. Built APIs for clients.';

    private const JOB = 'We are hiring a backend developer to build and maintain Laravel APIs, write tests and review code with the team.';

    /** @return array<string, array{0: string, 1: string, 2: array<string, mixed>}> */
    private function matrix(User $owner): array
    {
        $doc = CvDocument::create(['user_id' => $owner->id, 'name' => 'cv.pdf', 'disk_path' => 'cv/1/x.pdf', 'mime' => 'application/pdf', 'size' => 1, 'extracted_text' => 'text', 'expires_at' => now()->addHour()]);
        $version = CvVersion::create(['user_id' => $owner->id, 'name' => 'x', 'content' => self::CV, 'source' => 'manual']);
        $workspace = JobWorkspace::create(['user_id' => $owner->id, 'title' => 't', 'job_description' => self::JOB, 'cv_text' => self::CV]);
        $session = InterviewSession::create(['user_id' => $owner->id, 'title' => 't', 'cv_text' => self::CV, 'job_description' => self::JOB, 'transcript' => []]);
        $report = CareerReport::create(['user_id' => $owner->id, 'type' => 'skill_gap', 'input' => [], 'output' => 'x']);
        $app = Application::create(['user_id' => $owner->id, 'title' => 'Dev', 'company' => 'Acme', 'url' => 'https://example.com/job']);
        $search = JobSearch::create(['user_id' => $owner->id, 'name' => 'n', 'query' => 'php', 'country' => 'us']);

        $edit = ['name' => 'Hacked', 'content' => self::CV];

        return [
            'cv document destroy' => ['DELETE', "v1/cv-documents/{$doc->id}", []],
            'cv version show' => ['GET', "v1/cv-versions/{$version->id}", []],
            'cv version update' => ['PATCH', "v1/cv-versions/{$version->id}", $edit],
            'cv version destroy' => ['DELETE', "v1/cv-versions/{$version->id}", []],
            'workspace show' => ['GET', "v1/job-workspaces/{$workspace->id}", []],
            'interview show' => ['GET', "v1/interviews/{$session->id}", []],
            'interview reply' => ['POST', "v1/interviews/{$session->id}/reply", ['answer' => 'My answer']],
            'interview finish' => ['POST', "v1/interviews/{$session->id}/finish", []],
            'interview destroy' => ['DELETE', "v1/interviews/{$session->id}", []],
            'report destroy' => ['DELETE', "v1/reports/{$report->id}", []],
            'application show' => ['GET', "v1/applications/{$app->id}", []],
            'application update' => ['PATCH', "v1/applications/{$app->id}", ['status' => 'offer']],
            'application destroy' => ['DELETE', "v1/applications/{$app->id}", []],
            'saved search destroy' => ['DELETE', "v1/jobs/saved-searches/{$search->id}", []],
        ];
    }

    public function test_another_users_records_are_404(): void
    {
        $owner = $this->makeUser();
        $intruder = $this->makeUser();
        $matrix = $this->matrix($owner);

        foreach ($matrix as $label => [$method, $v1, $body]) {
            foreach (["/api/{$v1}"] as $path) {
                $response = $this->signIn($intruder)->json($method, $path, $body);
                $response->assertStatus(404);
                $this->assertSame('not_found', $response->json('code'), "{$label} {$path}");
                $this->assertStringNotContainsString('Hacked', $response->getContent());
                $this->assertStringNotContainsString('Experienced developer', $response->getContent(), "{$label} {$path} leaked data");
            }
        }

        $this->assertDatabaseCount('cv_documents', 1);
        $this->assertDatabaseCount('cv_versions', 1);
        $this->assertDatabaseCount('job_workspaces', 1);
        $this->assertDatabaseCount('interview_sessions', 1);
        $this->assertDatabaseCount('career_reports', 1);
        $this->assertDatabaseCount('applications', 1);
        $this->assertDatabaseCount('job_searches', 1);
        $this->assertSame('x', CvVersion::first()->name);
        $this->assertSame('saved', Application::first()->status);
        $this->assertSame('active', InterviewSession::first()->status);
    }

    public function test_the_owner_can_use_their_own_records_on_v1_routes(): void
    {
        $owner = $this->makeUser();
        $matrix = $this->matrix($owner);

        foreach (['cv version show', 'workspace show', 'interview show', 'application show'] as $label) {
            [$method, $v1, $body] = $matrix[$label];
            $this->signIn($owner)->json($method, "/api/{$v1}", $body)->assertOk();
        }
        [$method, $v1, $body] = $matrix['application update'];
        $this->signIn($owner)->json($method, "/api/{$v1}", $body)->assertOk()->assertJsonPath('status', 'offer');
        foreach (['cv version destroy', 'report destroy', 'saved search destroy', 'application destroy', 'interview destroy', 'cv document destroy'] as $label) {
            [$method, $v1] = $matrix[$label];
            $this->signIn($owner)->json($method, "/api/{$v1}")->assertStatus(204);
        }
    }

    public function test_references_to_another_users_records_in_a_body_are_404(): void
    {
        $owner = $this->makeUser();
        $intruder = $this->makeUser();
        $version = CvVersion::create(['user_id' => $owner->id, 'name' => 'x', 'content' => self::CV, 'source' => 'manual']);
        $workspace = JobWorkspace::create(['user_id' => $owner->id, 'title' => 't', 'job_description' => self::JOB, 'cv_text' => self::CV]);
        $app = ['title' => 'Dev', 'company' => 'Acme', 'url' => 'https://example.com/other', 'cv_version_id' => $version->id];

        $this->signIn($intruder)->postJson('/api/v1/applications', $app)->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->signIn($intruder)->postJson('/api/v1/cv-versions', ['name' => 'n', 'content' => self::CV, 'job_workspace_id' => $workspace->id])->assertStatus(404);
        $this->assertDatabaseCount('applications', 0);
        $this->assertDatabaseCount('cv_versions', 1);
    }

    public function test_lists_only_contain_the_signed_in_users_records(): void
    {
        $owner = $this->makeUser();
        $intruder = $this->makeUser();
        $this->matrix($owner);

        foreach (['cv-documents', 'cv-versions', 'job-workspaces', 'applications', 'jobs/saved-searches'] as $path) {
            $body = $this->signIn($intruder)->getJson("/api/v1/{$path}")->assertOk()->json();
            $rows = $body['data'] ?? $body;
            $this->assertSame([], $rows, "{$path} must be empty for another user");
        }
    }
}
