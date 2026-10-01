<?php

namespace Tests\Feature\Api;

use App\Models\CareerReport;
use App\Models\CvVersion;
use App\Models\Integration;
use App\Models\InterviewSession;
use App\Models\JobWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ComparesRoutes;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** /api/v1 career routes (the former CareerController) behave like their legacy twins. */
class V1CareerParityTest extends TestCase
{
    use ComparesRoutes;
    use CreatesUsers;
    use RefreshDatabase;

    private const CV = 'Experienced developer with PHP, Laravel and MySQL experience. Built APIs for clients.';

    private const JOB = 'We are hiring a backend developer to build and maintain Laravel APIs, write tests and review code with the team.';

    public function test_cv_version_routes_match(): void
    {
        $user = $this->makeUser();
        $body = ['name' => 'My CV', 'content' => self::CV, 'source' => 'manual'];

        $this->assertSameResponse($this->signIn($user)->postJson('/api/career/cv-versions', $body), $this->signIn($user)->postJson('/api/v1/cv-versions', $body), 'store');
        $this->assertSameResponse($this->signIn($user)->getJson('/api/career/cv-versions'), $this->signIn($user)->getJson('/api/v1/cv-versions'), 'index');

        [$a, $b] = CvVersion::orderBy('id')->pluck('id')->all();
        $this->assertSameResponse($this->signIn($user)->getJson("/api/career/cv-versions/{$a}"), $this->signIn($user)->getJson("/api/v1/cv-versions/{$b}"), 'show');
        $patch = ['name' => 'Renamed', 'content' => self::CV];
        $this->assertSameResponse($this->signIn($user)->patchJson("/api/career/cv-versions/{$a}", $patch), $this->signIn($user)->patchJson("/api/v1/cv-versions/{$b}", $patch), 'update');
        $this->assertSameResponse($this->signIn($user)->postJson('/api/career/cv-versions', []), $this->signIn($user)->postJson('/api/v1/cv-versions', []), 'validation');

        $this->signIn($user)->deleteJson("/api/career/cv-versions/{$a}")->assertStatus(204);
        $this->signIn($user)->deleteJson("/api/v1/cv-versions/{$b}")->assertStatus(204);
        $this->assertDatabaseCount('cv_versions', 0);
    }

    public function test_foreign_records_are_404_on_both(): void
    {
        $owner = $this->makeUser();
        $version = CvVersion::create(['user_id' => $owner->id, 'name' => 'x', 'content' => self::CV, 'source' => 'manual']);
        $workspace = JobWorkspace::create(['user_id' => $owner->id, 'title' => 't', 'job_description' => self::JOB, 'cv_text' => self::CV]);
        $session = InterviewSession::create(['user_id' => $owner->id, 'title' => 't', 'cv_text' => self::CV, 'job_description' => self::JOB, 'transcript' => []]);
        $report = CareerReport::create(['user_id' => $owner->id, 'type' => 'skill_gap', 'input' => [], 'output' => 'x']);
        $intruder = $this->makeUser();

        $pairs = [
            ['getJson', "/api/career/cv-versions/{$version->id}", "/api/v1/cv-versions/{$version->id}"],
            ['getJson', "/api/career/workspaces/{$workspace->id}", "/api/v1/job-workspaces/{$workspace->id}"],
            ['getJson', "/api/career/interviews/{$session->id}", "/api/v1/interviews/{$session->id}"],
            ['deleteJson', "/api/career/interviews/{$session->id}", "/api/v1/interviews/{$session->id}"],
            ['deleteJson', "/api/career/reports/{$report->id}", "/api/v1/reports/{$report->id}"],
        ];
        foreach ($pairs as [$verb, $legacy, $v1]) {
            $this->assertSameResponse($this->signIn($intruder)->{$verb}($legacy), $this->signIn($intruder)->{$verb}($v1), $v1);
            $this->signIn($intruder)->{$verb}($v1)->assertStatus(404);
        }
        $this->assertDatabaseCount('cv_versions', 1);
        $this->assertDatabaseCount('career_reports', 1);
        $this->assertDatabaseCount('interview_sessions', 1);
    }

    public function test_job_workspace_dashboard_analytics_and_library_match(): void
    {
        $user = $this->makeUser();
        $body = ['title' => 'Backend', 'company' => 'Acme', 'job_description' => self::JOB, 'cv_text' => self::CV];

        $this->assertSameResponse($this->signIn($user)->postJson('/api/career/workspaces', $body), $this->signIn($user)->postJson('/api/v1/job-workspaces', $body), 'store');
        $this->assertSameResponse($this->signIn($user)->getJson('/api/career/workspaces'), $this->signIn($user)->getJson('/api/v1/job-workspaces'), 'index');
        [$a, $b] = JobWorkspace::orderBy('id')->pluck('id')->all();
        $this->assertSameResponse($this->signIn($user)->getJson("/api/career/workspaces/{$a}"), $this->signIn($user)->getJson("/api/v1/job-workspaces/{$b}"), 'show');
        $this->assertSameResponse($this->signIn($user)->getJson('/api/career/dashboard'), $this->signIn($user)->getJson('/api/v1/me/dashboard'), 'dashboard');
        $this->assertSameResponse($this->signIn($user)->getJson('/api/career/analytics'), $this->signIn($user)->getJson('/api/v1/me/analytics'), 'analytics');
        $this->assertSameResponse($this->signIn($user)->getJson('/api/career/library?kind=workspace'), $this->signIn($user)->getJson('/api/v1/library?kind=workspace'), 'library');
        $this->assertSameResponse($this->signIn($user)->getJson('/api/career/library?kind=bogus'), $this->signIn($user)->getJson('/api/v1/library?kind=bogus'), 'library validation');
    }

    public function test_ai_report_routes_match(): void
    {
        $user = $this->makeUser();
        Integration::create(['provider' => 'openai', 'type' => 'ai', 'secret' => 'sk-test-secret-value', 'model' => 'gpt-4o-mini', 'enabled' => true, 'priority' => 1]);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'Generated answer']]]])]);
        $docs = ['cv_text' => self::CV, 'job_description' => self::JOB, 'title' => 'Backend', 'company' => 'Acme'];

        $routes = [
            'recruiter-view' => ['recruiter-view', $docs],
            'tailor-cv' => ['tailor-cv', $docs],
            'application-pack' => ['application-pack', $docs],
            'skill-gap' => ['skill-gap', $docs],
            'portfolio' => ['portfolio-review', $docs],
            'follow-up' => ['follow-up', ['type' => 'follow_up', 'title' => 'Backend', 'company' => 'Acme']],
        ];
        foreach ($routes as $legacy => [$v1, $payload]) {
            $this->assertSameResponse($this->signIn($user)->postJson("/api/career/{$legacy}", $payload), $this->signIn($user)->postJson("/api/v1/ai/{$v1}", $payload), $v1);
            $this->assertSameResponse($this->signIn($user)->postJson("/api/career/{$legacy}", []), $this->signIn($user)->postJson("/api/v1/ai/{$v1}", []), "{$v1} validation");
        }

        $this->assertSameResponse($this->signIn($user)->postJson('/api/career/diagnostic', []), $this->signIn($user)->postJson('/api/v1/ai/career-diagnostic', []), 'diagnostic needs 3 applications');
    }

    public function test_interview_routes_match(): void
    {
        $user = $this->makeUser();
        Integration::create(['provider' => 'openai', 'type' => 'ai', 'secret' => 'sk-test-secret-value', 'model' => 'gpt-4o-mini', 'enabled' => true, 'priority' => 1]);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'Tell me about yourself.']]]])]);
        $docs = ['cv_text' => self::CV, 'job_description' => self::JOB, 'title' => 'Backend'];

        $this->assertSameResponse($this->signIn($user)->postJson('/api/career/interviews', $docs), $this->signIn($user)->postJson('/api/v1/interviews', $docs), 'start');
        [$a, $b] = InterviewSession::orderBy('id')->pluck('id')->all();

        $this->assertSameResponse($this->signIn($user)->getJson("/api/career/interviews/{$a}"), $this->signIn($user)->getJson("/api/v1/interviews/{$b}"), 'show');
        $reply = ['answer' => 'I built APIs.'];
        $this->assertSameResponse($this->signIn($user)->postJson("/api/career/interviews/{$a}/reply", $reply), $this->signIn($user)->postJson("/api/v1/interviews/{$b}/reply", $reply), 'reply');
        $this->assertSameResponse($this->signIn($user)->postJson("/api/career/interviews/{$a}/finish"), $this->signIn($user)->postJson("/api/v1/interviews/{$b}/finish"), 'finish');
        $this->assertSameResponse($this->signIn($user)->postJson("/api/career/interviews/{$a}/reply", $reply), $this->signIn($user)->postJson("/api/v1/interviews/{$b}/reply", $reply), 'reply after finish');
    }

    public function test_legacy_career_routes_announce_their_successors(): void
    {
        $user = $this->makeUser();

        $this->assertDeprecated($this->signIn($user)->getJson('/api/career/dashboard'), '/api/v1/me/dashboard');
        $this->assertDeprecated($this->signIn($user)->getJson('/api/career/cv-versions/5'), '/api/v1/cv-versions/5');
        $this->assertDeprecated($this->signIn($user)->postJson('/api/career/portfolio', []), '/api/v1/ai/portfolio-review');
        $this->assertDeprecated($this->signIn($user)->postJson('/api/career/interviews/7/finish'), '/api/v1/interviews/7/finish');
        $this->assertNotDeprecated($this->signIn($user)->getJson('/api/v1/me/dashboard'));
    }

    public function test_v1_career_routes_require_a_session(): void
    {
        foreach (['me/dashboard', 'me/analytics', 'library', 'cv-versions', 'job-workspaces'] as $path) {
            $this->getJson("/api/v1/{$path}")->assertStatus(401);
        }
    }
}
