<?php

namespace Tests\Feature\Api;

use App\Models\Application;
use App\Models\CvVersion;
use App\Models\InterviewSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * One invalid input per FormRequest: always a 422 in the shared envelope with the field named in `errors`.
 * `{app}`, `{version}`, `{session}` in a path stand for records owned by the signed-in user, so
 * validation (not ownership) is what fails. Each case runs on its v1 path.
 */
class ValidationTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string, 2: string, 3: array<string, mixed>|string, 4: array<int, string>}> */
    public static function invalidInputs(): array
    {
        $cv = str_repeat('Experienced developer. ', 3);
        $job = str_repeat('We are hiring a backend developer. ', 3);
        $cases = [
            // method, path, role, body (array) or query string, fields that must be reported
            'register empty' => ['POST', 'auth/register', 'anon', [], ['name', 'email', 'password']],
            'register bad email' => ['POST', 'auth/register', 'anon', ['name' => 'A', 'email' => 'nope', 'password' => 'a-long-password-1', 'password_confirmation' => 'a-long-password-1'], ['email']],
            'register short password' => ['POST', 'auth/register', 'anon', ['name' => 'A', 'email' => 'a@example.com', 'password' => 'short', 'password_confirmation' => 'short'], ['password']],
            'register mismatch' => ['POST', 'auth/register', 'anon', ['name' => 'A', 'email' => 'a@example.com', 'password' => 'a-long-password-1', 'password_confirmation' => 'different-pass-2'], ['password']],
            'login empty' => ['POST', 'auth/login', 'anon', [], ['email', 'password']],
            'otp request purpose' => ['POST', 'auth/otp/request', 'anon', ['email' => 'a@example.com', 'purpose' => 'hack'], ['purpose']],
            'otp verify code' => ['POST', 'auth/otp/verify', 'anon', ['email' => 'a@example.com', 'purpose' => 'verify', 'code' => 'abc'], ['code']],
            'contact empty' => ['POST', 'contact-messages', 'anon', [], ['name', 'email', 'topic', 'message']],
            'contact topic' => ['POST', 'contact-messages', 'anon', ['name' => 'A', 'email' => 'a@example.com', 'topic' => 'spam', 'message' => str_repeat('x', 30)], ['topic']],
            'analytics event empty' => ['POST', 'analytics/events', 'anon', [], ['consent', 'visitor_id', 'session_id', 'path']],
            'profile language' => ['PATCH', 'me', 'user', ['language' => 'xx'], ['language']],
            'profile country' => ['PATCH', 'me', 'user', ['country' => 'USA'], ['country']],
            'password empty' => ['PUT', 'me/password', 'user', [], ['current_password', 'password']],
            'cv upload no file' => ['POST', 'cv-documents', 'user', [], ['file']],
            'cv upload is_primary' => ['POST', 'cv-documents', 'user', ['is_primary' => 'maybe'], ['file', 'is_primary']],
            'cv extract no file' => ['POST', 'cv-documents/extract', 'user', [], ['file']],
            'application empty' => ['POST', 'applications', 'user', [], ['title', 'company', 'url']],
            'application status' => ['POST', 'applications', 'user', ['title' => 't', 'company' => 'c', 'url' => 'https://example.com/j', 'status' => 'bogus'], ['status']],
            'application http url' => ['POST', 'applications', 'user', ['title' => 't', 'company' => 'c', 'url' => 'http://example.com/j'], ['url']],
            'application update status' => ['PATCH', 'applications/{app}', 'user', ['status' => 'bogus'], ['status']],
            'application update notes too long' => ['PATCH', 'applications/{app}', 'user', ['notes' => str_repeat('x', 5001)], ['notes']],
            'applications per_page 0' => ['GET', 'applications', 'user', 'per_page=0', ['per_page']],
            'applications per_page 51' => ['GET', 'applications', 'user', 'per_page=51', ['per_page']],
            'applications page 0' => ['GET', 'applications', 'user', 'page=0', ['page']],
            'applications page text' => ['GET', 'applications', 'user', 'page=abc', ['page']],
            'cv version empty' => ['POST', 'cv-versions', 'user', [], ['name', 'content']],
            'cv version source' => ['POST', 'cv-versions', 'user', ['name' => 'n', 'content' => $cv, 'source' => 'scan'], ['source']],
            'cv version update empty' => ['PATCH', 'cv-versions/{version}', 'user', [], ['name', 'content']],
            'workspace empty' => ['POST', 'job-workspaces', 'user', [], ['title', 'job_description', 'cv_text']],
            'interview start empty' => ['POST', 'interviews', 'user', [], ['cv_text', 'job_description', 'title']],
            'interview reply short' => ['POST', 'interviews/{session}/reply', 'user', ['answer' => 'x'], ['answer']],
            'library kind' => ['GET', 'library', 'user', 'kind=bogus', ['kind']],
            'library per_page' => ['GET', 'library', 'user', 'per_page=99', ['per_page']],
            'chat empty' => ['POST', 'ai/chat', 'user', [], ['message']],
            'cover letter empty' => ['POST', 'ai/cover-letter', 'user', [], []],
            'ats analysis short' => ['POST', 'ai/ats-analysis', 'user', ['cv_text' => 'short'], ['cv_text']],
            'ats analysis format' => ['POST', 'ai/ats-analysis', 'user', ['cv_text' => $cv, 'report_format' => 'xml'], ['report_format']],
            'ats document empty' => ['POST', 'ats/document', 'user', [], []],
            'recruiter view empty' => ['POST', 'ai/recruiter-view', 'user', [], ['cv_text', 'job_description']],
            'tailor cv title' => ['POST', 'ai/tailor-cv', 'user', ['cv_text' => $cv, 'job_description' => $job], ['title']],
            'application pack empty' => ['POST', 'ai/application-pack', 'user', [], ['cv_text', 'job_description', 'title']],
            'skill gap empty' => ['POST', 'ai/skill-gap', 'user', [], ['cv_text', 'job_description']],
            'portfolio url' => ['POST', 'ai/portfolio-review', 'user', ['cv_text' => $cv, 'job_description' => $job, 'github_url' => 'http://github.com/x'], ['github_url']],
            'follow up type' => ['POST', 'ai/follow-up', 'user', ['type' => 'spam', 'title' => 't', 'company' => 'c'], ['type']],
            'jobs search empty' => ['POST', 'jobs/search', 'user', [], []],
            'saved search empty' => ['POST', 'jobs/saved-searches', 'user', [], ['name', 'query', 'country']],
            'saved search mode' => ['POST', 'jobs/saved-searches', 'user', ['name' => 'n', 'query' => 'q', 'country' => 'us', 'work_mode' => 'moon'], ['work_mode']],
            'admin users per_page' => ['GET', 'admin/users', 'admin', 'per_page=51', ['per_page']],
            'admin users search too long' => ['GET', 'admin/users', 'admin', 'search='.str_repeat('a', 121), ['search']],
            'admin suspend type' => ['PATCH', 'admin/users/{user}', 'admin', ['suspended' => 'maybe'], ['suspended']],
            'admin warning empty' => ['POST', 'admin/users/{user}/warnings', 'admin', [], ['subject', 'message']],
            'admin warning severity' => ['POST', 'admin/users/{user}/warnings', 'admin', ['subject' => 's', 'message' => 'Please stop.', 'severity' => 'doom'], ['severity']],
            'admin contact status' => ['GET', 'admin/contact-messages', 'admin', 'status=bogus', ['status']],
            'admin audit page' => ['GET', 'admin/audit-events', 'admin', 'page=0', ['page']],
            'admin applications status' => ['GET', 'admin/applications', 'admin', 'status=bogus', ['status']],
            'admin analytics days 14' => ['GET', 'admin/analytics', 'admin', 'days=14', ['days']],
            'admin analytics days text' => ['GET', 'admin/analytics', 'admin', 'days=week', ['days']],
            'admin template empty' => ['POST', 'admin/cv-templates', 'admin', [], ['name', 'design', 'sample']],
            'admin integration empty' => ['POST', 'admin/integrations', 'admin', [], []],
            'admin reorder empty' => ['POST', 'admin/integrations/reorder', 'admin', [], []],
            'admin smtp empty' => ['PUT', 'admin/smtp', 'admin', [], ['host', 'port', 'encryption', 'from_address', 'from_name']],
            'admin smtp port' => ['PUT', 'admin/smtp', 'admin', ['host' => 'smtp.example.com', 'port' => 70000, 'encryption' => 'tls', 'from_address' => 'a@example.com', 'from_name' => 'A'], ['port']],
            'admin site settings' => ['PUT', 'admin/site-settings', 'admin', [], []],
        ];

        return array_map(fn ($c) => $c, $cases);
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_is_422_with_field_errors(string $method, string $path, string $role, array|string $input, array $fields): void
    {
        $user = $this->makeUser();
        $actor = $role === 'admin' ? $this->makeAdmin() : $user;
        $target = $this->makeUser();

        $replace = [
            '{app}' => (string) Application::create(['user_id' => $actor->id, 'title' => 'Dev', 'company' => 'Acme', 'url' => 'https://example.com/job'])->id,
            '{version}' => (string) CvVersion::create(['user_id' => $actor->id, 'name' => 'x', 'content' => str_repeat('Experienced developer. ', 3), 'source' => 'manual'])->id,
            '{session}' => (string) InterviewSession::create(['user_id' => $actor->id, 'title' => 't', 'cv_text' => 'cv', 'job_description' => 'job', 'transcript' => []])->id,
            '{user}' => (string) $target->id,
        ];
        $uri = '/api/v1/'.strtr($path, $replace);
        $client = $role === 'anon' ? $this : $this->signIn($actor);

        $response = is_string($input)
            ? $client->getJson($uri.'?'.$input)
            : $client->json($method, $uri, $input);

        $response->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        foreach ($fields as $field) {
            $this->assertArrayHasKey($field, $response->json('errors') ?? [], "{$field} should be reported for {$path}");
        }
    }

    public function test_empty_status_and_unknown_fields_are_not_rejected(): void
    {
        $admin = $this->makeAdmin();

        // The admin UI sends `status=` (empty) for "all messages".
        $this->signIn($admin)->getJson('/api/v1/admin/contact-messages?page=1&status=')->assertOk();
        $this->signIn($admin)->getJson('/api/v1/admin/applications?per_page=5')->assertOk();

        // Unknown body fields are dropped, not stored.
        $user = $this->makeUser();
        $this->signIn($user)->postJson('/api/v1/applications', ['title' => 't', 'company' => 'c', 'url' => 'https://example.com/j', 'user_id' => 999, 'is_admin' => true])->assertStatus(201);
        $this->assertSame($user->id, Application::first()->user_id);
    }

    public function test_per_page_changes_the_page_size_and_keeps_the_default(): void
    {
        $user = $this->makeUser();
        foreach (range(1, 25) as $i) {
            Application::create(['user_id' => $user->id, 'title' => "t{$i}", 'company' => 'c', 'url' => "https://example.com/{$i}"]);
        }

        $this->signIn($user)->getJson('/api/v1/applications')->assertOk()->assertJsonPath('per_page', 20)->assertJsonCount(20, 'data');
        $this->signIn($user)->getJson('/api/v1/applications?per_page=5')->assertOk()->assertJsonPath('per_page', 5)->assertJsonCount(5, 'data');
        $this->signIn($user)->getJson('/api/applications?per_page=50')->assertOk()->assertJsonCount(25, 'data');
    }

    public function test_a_foreign_record_stays_404_even_when_the_body_is_invalid(): void
    {
        $owner = $this->makeUser();
        $intruder = $this->makeUser();
        $app = Application::create(['user_id' => $owner->id, 'title' => 'Dev', 'company' => 'Acme', 'url' => 'https://example.com/job']);
        $session = InterviewSession::create(['user_id' => $owner->id, 'title' => 't', 'cv_text' => 'cv', 'job_description' => 'job', 'transcript' => []]);

        $this->signIn($intruder)->patchJson("/api/v1/applications/{$app->id}", ['status' => 'bogus'])->assertStatus(404);
        $this->signIn($intruder)->postJson("/api/v1/interviews/{$session->id}/reply", ['answer' => 'x'])->assertStatus(404);
        $this->signIn($intruder)->patchJson("/api/applications/{$app->id}", ['status' => 'bogus'])->assertStatus(404);
    }

    public function test_every_form_request_builds_its_rules(): void
    {
        $count = 0;
        foreach (glob(app_path('Http/Requests/*/*.php')) as $file) {
            $class = 'App\\Http\\Requests\\'.basename(dirname($file)).'\\'.basename($file, '.php');
            $rules = (new $class)->rules();
            $this->assertIsArray($rules, $class);
            $this->assertNotEmpty($rules, $class);
            $count++;
        }
        $this->assertGreaterThan(30, $count);
    }
}
