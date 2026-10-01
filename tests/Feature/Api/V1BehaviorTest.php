<?php

namespace Tests\Feature\Api;

use App\Models\Application;
use App\Models\CareerReport;
use App\Models\CvDocument;
use App\Models\CvTemplate;
use App\Models\CvVersion;
use App\Models\Integration;
use App\Models\InterviewSession;
use App\Models\JobSearch;
use App\Models\JobWorkspace;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * What every /api/v1 route answers on its own. Until S7 these were asserted as "same as the legacy alias";
 * with the aliases gone they are asserted directly.
 */
class V1BehaviorTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    private const CV = 'Experienced developer with PHP, Laravel and MySQL experience. Built APIs for clients.';

    private const JOB = 'We are hiring a backend developer to build and maintain Laravel APIs, write tests and review code with the team.';

    private function cvFile(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('cv.txt', "Experience\nBuilt web applications with PHP and Laravel for several clients.\nEducation\nBachelor degree in computer science.");
    }

    private function aiProvider(string $answer): void
    {
        Integration::create(['provider' => 'openai', 'type' => 'ai', 'secret' => 'sk-test-secret-value', 'model' => 'gpt-4o-mini', 'enabled' => true, 'priority' => 1]);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => $answer]]]])]);
    }

    // --- public and account -----------------------------------------------------

    public function test_public_reads_are_open(): void
    {
        $this->getJson('/api/v1/csrf')->assertOk()->assertJsonStructure(['token']);
        $this->getJson('/api/v1/site-settings')->assertOk();
        $this->getJson('/api/v1/cv-templates')->assertOk();
    }

    public function test_contact_messages_accepts_a_message(): void
    {
        $payload = ['name' => 'V', 'email' => 'v@example.test', 'topic' => 'feedback', 'message' => 'A sufficiently long message.'];

        $this->postJson('/api/v1/contact-messages', $payload)->assertStatus(201)->assertJsonStructure(['message', 'reference']);
        $this->assertDatabaseCount('support_messages', 1);
    }

    public function test_analytics_events_accepts_a_consented_event(): void
    {
        $payload = ['consent' => true, 'visitor_id' => str_repeat('a', 20), 'session_id' => str_repeat('b', 20), 'path' => '/'];

        $this->postJson('/api/v1/analytics/events', $payload)->assertSuccessful();
    }

    public function test_register_login_and_otp_answer_on_the_auth_prefix(): void
    {
        $this->fakePlatformMail();
        $register = fn (string $email) => $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada', 'email' => $email, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ]);
        $first = $register('a1@example.test')->assertStatus(201);
        $this->assertSame($first->json(), $register('a1@example.test')->assertStatus(201)->json());

        $this->makeUser(['email' => 'login@example.test']);
        $login = fn (string $password) => $this->postJson('/api/v1/auth/login', ['email' => 'login@example.test', 'password' => $password]);
        $login('wrong-password-123')->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
        $login(self::PASSWORD)->assertOk();

        $this->makeUser(['email' => 'otp@example.test', 'verified_at' => null]);
        $this->postJson('/api/v1/auth/otp/request', ['email' => 'otp@example.test', 'purpose' => 'verify'])->assertSuccessful();
        $this->postJson('/api/v1/auth/otp/verify', ['email' => 'otp@example.test', 'purpose' => 'verify', 'code' => '000000'])->assertStatus(422);
    }

    public function test_me_family(): void
    {
        $user = $this->makeUser();

        // The test client keeps the seeded session, so check signed out first.
        $this->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->signIn($user)->getJson('/api/v1/me')->assertOk()->assertJsonPath('email', $user->email);
        $this->signIn($user)->patchJson('/api/v1/me', ['city' => 'Rabat'])->assertOk()->assertJsonPath('city', 'Rabat');
        $this->signIn($user)->getJson('/api/v1/me/export')->assertOk();
    }

    public function test_password_change_is_put_and_post_is_not_allowed(): void
    {
        $user = $this->makeUser();
        $body = fn (string $current) => ['current_password' => $current, 'password' => 'another-long-passphrase', 'password_confirmation' => 'another-long-passphrase'];

        $this->signIn($user)->putJson('/api/v1/me/password', $body('nope'))->assertStatus(422);
        $this->signIn($user)->putJson('/api/v1/me/password', $body(self::PASSWORD))->assertOk()->assertExactJson(['message' => 'Password changed.']);
        $this->assertTrue(Hash::check('another-long-passphrase', $user->refresh()->password));
        $this->signIn($user->refresh())->postJson('/api/v1/me/password', $body('another-long-passphrase'))->assertStatus(405);
    }

    public function test_logout_is_204_and_audited(): void
    {
        $user = $this->makeUser();

        $response = $this->signIn($user)->postJson('/api/v1/auth/logout');
        $response->assertStatus(204);
        $this->assertSame('', $response->getContent());
        $this->assertDatabaseHas('audit_events', ['event' => 'logout', 'user_id' => $user->id]);
    }

    public function test_account_deletion_removes_the_user(): void
    {
        $user = $this->makeUser();

        $this->signIn($user)->deleteJson('/api/v1/me', ['current_password' => self::PASSWORD, 'confirmation' => 'DELETE'])->assertOk();
        $this->assertNull(User::find($user->id));
    }

    // --- cv documents -----------------------------------------------------------

    public function test_cv_document_routes(): void
    {
        Storage::fake('local');
        $user = $this->makeUser();

        $this->signIn($user)->getJson('/api/v1/cv-documents')->assertOk();
        $id = $this->signIn($user)->post('/api/v1/cv-documents', ['file' => $this->cvFile()], ['Accept' => 'application/json'])->assertStatus(201)->json('id');
        $this->signIn($user)->post('/api/v1/cv-documents/extract', ['file' => $this->cvFile()], ['Accept' => 'application/json'])->assertOk()->assertJsonStructure(['text']);

        $this->signIn($user)->deleteJson("/api/v1/cv-documents/{$id}")->assertStatus(204);
        $this->assertDatabaseCount('cv_documents', 0);
    }

    public function test_deleting_another_users_cv_document_is_404(): void
    {
        $doc = CvDocument::create(['user_id' => $this->makeUser()->id, 'name' => 'cv.pdf', 'disk_path' => null, 'mime' => 'application/pdf', 'size' => 1, 'extracted_text' => 'text', 'expires_at' => now()->addHour()]);

        $this->signIn($this->makeUser())->deleteJson("/api/v1/cv-documents/{$doc->id}")->assertStatus(404);
        $this->assertDatabaseHas('cv_documents', ['id' => $doc->id]);
    }

    public function test_the_analyze_improve_cv_and_admin_download_endpoints_do_not_exist(): void
    {
        $user = $this->makeUser();

        $this->signIn($user)->postJson('/api/v1/cv-documents/1/analyze', [])->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->signIn($user)->postJson('/api/v1/ai/improve-cv', [])->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->signIn($this->makeAdmin())->getJson('/api/v1/admin/cv-documents/5/file')->assertStatus(404)->assertJsonPath('code', 'not_found');
    }

    // --- applications and jobs --------------------------------------------------

    private function applicationPayload(string $url): array
    {
        return ['title' => 'Backend Developer', 'company' => 'Acme', 'url' => $url];
    }

    public function test_application_routes(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser();

        $id = $this->signIn($user)->postJson('/api/v1/applications', $this->applicationPayload('https://example.test/one'))->assertStatus(201)->json('id');
        $this->signIn($user)->postJson('/api/v1/applications', $this->applicationPayload('https://example.test/two'))->assertStatus(201);

        $this->assertSame(2, $this->signIn($user)->getJson('/api/v1/applications')->json('total'));
        $this->assertSame(0, $this->signIn($other)->getJson('/api/v1/applications')->json('total'));
        $this->signIn($user)->patchJson("/api/v1/applications/{$id}", ['status' => 'interview'])->assertOk()->assertJsonPath('status', 'interview');
        $this->signIn($user)->putJson("/api/v1/applications/{$id}", ['status' => 'offer'])->assertOk()->assertJsonPath('status', 'offer');
        $this->signIn($user)->deleteJson("/api/v1/applications/{$id}")->assertStatus(204);
    }

    public function test_application_show_returns_the_owners_record_and_404_for_anyone_else(): void
    {
        $owner = $this->makeUser();
        $application = Application::create(['user_id' => $owner->id] + $this->applicationPayload('https://example.test/show'));

        $this->signIn($owner)->getJson("/api/v1/applications/{$application->id}")->assertOk()->assertJsonPath('id', $application->id)->assertJsonPath('company', 'Acme');
        $intruder = $this->makeUser();
        $this->signIn($intruder)->getJson("/api/v1/applications/{$application->id}")->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->signIn($intruder)->getJson('/api/v1/applications/999999')->assertStatus(404)->assertJsonPath('code', 'not_found');
    }

    public function test_application_show_requires_a_session_and_a_numeric_id(): void
    {
        $this->getJson('/api/v1/applications/1')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->signIn($this->makeUser())->getJson('/api/v1/applications/abc')->assertStatus(404);
    }

    public function test_anonymous_requests_for_a_missing_id_get_401_not_a_404_oracle(): void
    {
        $this->deleteJson('/api/v1/applications/999')->assertStatus(401);
        $this->getJson('/api/v1/applications/999')->assertStatus(401);
        $this->deleteJson('/api/v1/cv-documents/999')->assertStatus(401);
    }

    public function test_job_routes(): void
    {
        $user = $this->makeUser();

        $this->signIn($user)->getJson('/api/v1/jobs/links?q=developer&country=MA')->assertOk();
        $this->signIn($user)->postJson('/api/v1/jobs/saved-searches', ['name' => 'Remote PHP', 'query' => 'php developer', 'country' => 'MA'])->assertSuccessful();
        $this->signIn($user)->getJson('/api/v1/jobs/saved-searches')->assertOk();
        $this->signIn($user)->deleteJson('/api/v1/jobs/saved-searches/'.JobSearch::first()->id)->assertStatus(204);
        $this->signIn($user)->postJson('/api/v1/jobs/search', [])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    }

    // --- ai and ats -------------------------------------------------------------

    public function test_ai_routes_validate_and_report_provider_failures(): void
    {
        $user = $this->makeUser();

        $this->signIn($user)->postJson('/api/v1/ai/cover-letter', [])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->signIn($user)->postJson('/api/v1/ai/chat', ['message' => 'hello'])->assertStatus(503);

        Integration::create(['provider' => 'openai', 'type' => 'ai', 'secret' => 'sk-test-secret-value', 'model' => 'gpt-4o-mini', 'enabled' => true, 'priority' => 1]);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'not json']]]])]);
        $body = ['cv_text' => str_repeat('Developer with experience in web applications. ', 3), 'report_format' => 'structured'];
        $this->signIn($user)->postJson('/api/v1/ai/ats-analysis', $body)->assertStatus(502)->assertJsonPath('code', 'upstream_invalid_response');
    }

    public function test_the_document_review_endpoint(): void
    {
        $cv = "Profile\nDeveloper with several years of experience building web applications for clients.\ncontact@example.test\nExperience\n2024-2025 ACME\n- Developed a client dashboard for 50 customers.\nEducation\nBachelor degree";

        $this->signIn($this->makeUser())->postJson('/api/v1/ats/document', ['cv_text' => $cv])->assertOk();
    }

    // --- career -----------------------------------------------------------------

    public function test_cv_version_routes(): void
    {
        $user = $this->makeUser();
        $body = ['name' => 'My CV', 'content' => self::CV, 'source' => 'manual'];

        $id = $this->signIn($user)->postJson('/api/v1/cv-versions', $body)->assertSuccessful()->json('id');
        $this->signIn($user)->getJson('/api/v1/cv-versions')->assertOk();
        $this->signIn($user)->getJson("/api/v1/cv-versions/{$id}")->assertOk()->assertJsonPath('name', 'My CV');
        $this->signIn($user)->patchJson("/api/v1/cv-versions/{$id}", ['name' => 'Renamed', 'content' => self::CV])->assertOk()->assertJsonPath('name', 'Renamed');
        $this->signIn($user)->postJson('/api/v1/cv-versions', [])->assertStatus(422);
        $this->signIn($user)->deleteJson("/api/v1/cv-versions/{$id}")->assertStatus(204);
        $this->assertDatabaseCount('cv_versions', 0);
    }

    public function test_foreign_career_records_are_404(): void
    {
        $owner = $this->makeUser();
        $version = CvVersion::create(['user_id' => $owner->id, 'name' => 'x', 'content' => self::CV, 'source' => 'manual']);
        $workspace = JobWorkspace::create(['user_id' => $owner->id, 'title' => 't', 'job_description' => self::JOB, 'cv_text' => self::CV]);
        $session = InterviewSession::create(['user_id' => $owner->id, 'title' => 't', 'cv_text' => self::CV, 'job_description' => self::JOB, 'transcript' => []]);
        $report = CareerReport::create(['user_id' => $owner->id, 'type' => 'skill_gap', 'input' => [], 'output' => 'x']);
        $intruder = $this->makeUser();

        foreach ([
            ['getJson', "/api/v1/cv-versions/{$version->id}"],
            ['getJson', "/api/v1/job-workspaces/{$workspace->id}"],
            ['getJson', "/api/v1/interviews/{$session->id}"],
            ['deleteJson', "/api/v1/interviews/{$session->id}"],
            ['deleteJson', "/api/v1/reports/{$report->id}"],
        ] as [$verb, $path]) {
            $this->signIn($intruder)->{$verb}($path)->assertStatus(404);
        }
        $this->assertDatabaseCount('cv_versions', 1);
        $this->assertDatabaseCount('career_reports', 1);
        $this->assertDatabaseCount('interview_sessions', 1);
    }

    public function test_job_workspace_dashboard_analytics_and_library(): void
    {
        $user = $this->makeUser();
        $body = ['title' => 'Backend', 'company' => 'Acme', 'job_description' => self::JOB, 'cv_text' => self::CV];

        $id = $this->signIn($user)->postJson('/api/v1/job-workspaces', $body)->assertSuccessful()->json('id');
        $this->signIn($user)->getJson('/api/v1/job-workspaces')->assertOk();
        $this->signIn($user)->getJson("/api/v1/job-workspaces/{$id}")->assertOk();
        $this->signIn($user)->getJson('/api/v1/me/dashboard')->assertOk();
        $this->signIn($user)->getJson('/api/v1/me/analytics')->assertOk();
        $this->signIn($user)->getJson('/api/v1/library?kind=workspace')->assertOk();
        $this->signIn($user)->getJson('/api/v1/library?kind=bogus')->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    }

    public function test_ai_report_routes(): void
    {
        $user = $this->makeUser();
        $this->aiProvider('Generated answer');
        $docs = ['cv_text' => self::CV, 'job_description' => self::JOB, 'title' => 'Backend', 'company' => 'Acme'];

        foreach (['recruiter-view', 'tailor-cv', 'application-pack', 'skill-gap', 'portfolio-review'] as $route) {
            $this->signIn($user)->postJson("/api/v1/ai/{$route}", $docs)->assertOk()->assertJsonStructure(['answer']);
            $this->signIn($user)->postJson("/api/v1/ai/{$route}", [])->assertStatus(422);
        }
        $this->signIn($user)->postJson('/api/v1/ai/follow-up', ['type' => 'follow_up', 'title' => 'Backend', 'company' => 'Acme'])->assertOk();
        $this->signIn($user)->postJson('/api/v1/ai/career-diagnostic', [])->assertStatus(422);
    }

    public function test_interview_routes(): void
    {
        $user = $this->makeUser();
        $this->aiProvider('Tell me about yourself.');

        $id = $this->signIn($user)->postJson('/api/v1/interviews', ['cv_text' => self::CV, 'job_description' => self::JOB, 'title' => 'Backend'])->assertSuccessful()->json('session_id');
        $this->signIn($user)->getJson("/api/v1/interviews/{$id}")->assertOk();
        $this->signIn($user)->postJson("/api/v1/interviews/{$id}/reply", ['answer' => 'I built APIs.'])->assertOk();
        $this->signIn($user)->postJson("/api/v1/interviews/{$id}/finish")->assertOk();
        $this->signIn($user)->postJson("/api/v1/interviews/{$id}/reply", ['answer' => 'I built APIs.'])->assertStatus(422);
    }

    // --- admin ------------------------------------------------------------------

    public function test_admin_writes_are_403_for_a_normal_user(): void
    {
        $user = $this->makeUser();
        $target = $this->makeUser();

        $this->signIn($user)->patchJson("/api/v1/admin/users/{$target->id}", ['suspended' => true])->assertStatus(403);
        $this->signIn($user)->putJson('/api/v1/admin/site-settings', [])->assertStatus(403);
        $this->signIn($user)->postJson('/api/v1/admin/integrations', [])->assertStatus(403);
        $this->signIn($user)->deleteJson('/api/v1/admin/cache')->assertStatus(403);
        $this->signIn($user)->postJson("/api/v1/admin/users/{$target->id}/warnings", [])->assertStatus(403);
        $this->assertFalse($target->refresh()->suspended);
    }

    public function test_admin_cache_clear_is_a_delete_with_204(): void
    {
        $this->signIn($this->makeAdmin())->deleteJson('/api/v1/admin/cache')->assertStatus(204);
    }

    public function test_a_warning_is_201_and_sends_one_mail(): void
    {
        $this->fakePlatformMail();
        $admin = $this->makeAdmin();
        $user = $this->makeUser();
        $body = ['subject' => 'Policy reminder', 'message' => 'Please keep uploads professional.', 'severity' => 'notice'];

        $this->signIn($admin)->postJson("/api/v1/admin/users/{$user->id}/warnings", $body)->assertStatus(201)->assertJsonStructure(['message', 'email_sent']);
        $this->assertCount(1, $this->sentMail);
        $this->signIn($admin)->postJson("/api/v1/admin/users/{$user->id}/warnings", [])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    }

    public function test_user_suspension_and_detail(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeUser();

        $this->signIn($admin)->getJson("/api/v1/admin/users/{$user->id}")->assertOk()->assertJsonStructure(['user', 'counts', 'activity', 'uploads', 'cv_versions']);
        $this->signIn($admin)->patchJson("/api/v1/admin/users/{$user->id}", ['suspended' => true])->assertOk()->assertJsonPath('suspended', true);
        $this->assertTrue($user->refresh()->suspended);
        $this->signIn($admin)->getJson('/api/v1/admin/users/999999')->assertStatus(404);
    }

    public function test_template_message_and_integration_writes(): void
    {
        $admin = $this->makeAdmin();
        $tpl = [
            'name' => 'Modern', 'description' => 'Clean', 'published' => true,
            'design' => ['layout' => 'modern', 'accent' => '#112233', 'font' => 'sans', 'spacing' => 'compact'],
            'sample' => ['name' => 'Ada', 'experience' => [], 'education' => []],
        ];
        $this->signIn($admin)->postJson('/api/v1/admin/cv-templates', $tpl)->assertSuccessful();
        $this->signIn($admin)->postJson('/api/v1/admin/cv-templates', [])->assertStatus(422);
        $this->signIn($admin)->deleteJson('/api/v1/admin/cv-templates/'.CvTemplate::first()->id)->assertStatus(204);

        $message = SupportMessage::create(['name' => 'A', 'email' => 'a@example.com', 'topic' => 'feedback', 'message' => 'A long enough message body.', 'status' => 'new']);
        $this->signIn($admin)->patchJson("/api/v1/admin/contact-messages/{$message->id}", ['status' => 'read'])->assertOk()->assertJsonPath('status', 'read');

        $this->signIn($admin)->postJson('/api/v1/admin/integrations', ['provider' => 'openai', 'type' => 'ai', 'secret' => 'sk-super-secret', 'model' => 'gpt-4o-mini', 'enabled' => true])->assertSuccessful();
        $this->assertStringNotContainsString('sk-super-secret', $this->signIn($admin)->getJson('/api/v1/admin/integrations')->getContent());
        $this->signIn($admin)->patchJson('/api/v1/admin/integrations/'.Integration::first()->id, ['model' => 'gpt-4o'])->assertOk();
    }
}
