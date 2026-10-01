<?php

namespace Tests\Feature\Api;

use App\Models\Application;
use App\Models\CvDocument;
use App\Models\Integration;
use App\Models\JobSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ComparesRoutes;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** /api/v1 cv-documents, applications, jobs, ai and ats routes behave like their legacy twins. */
class V1DocumentsApplicationsParityTest extends TestCase
{
    use ComparesRoutes;
    use CreatesUsers;
    use RefreshDatabase;

    private function cvFile(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('cv.txt', "Experience\nBuilt web applications with PHP and Laravel for several clients.\nEducation\nBachelor degree in computer science.");
    }

    // --- cv-documents -----------------------------------------------------------

    public function test_cv_document_routes_match(): void
    {
        Storage::fake('local');
        $user = $this->makeUser();

        $this->assertSameResponse($this->signIn($user)->getJson('/api/cv'), $this->signIn($user)->getJson('/api/v1/cv-documents'), 'index');

        $legacy = $this->signIn($user)->post('/api/cv', ['file' => $this->cvFile()], ['Accept' => 'application/json']);
        $v1 = $this->signIn($user)->post('/api/v1/cv-documents', ['file' => $this->cvFile()], ['Accept' => 'application/json']);
        $this->assertSameResponse($legacy, $v1, 'store');
        $v1->assertStatus(201);

        $this->assertSameResponse(
            $this->signIn($user)->post('/api/cv/extract', ['file' => $this->cvFile()], ['Accept' => 'application/json']),
            $this->signIn($user)->post('/api/v1/cv-documents/extract', ['file' => $this->cvFile()], ['Accept' => 'application/json']),
            'extract'
        );

        $ids = CvDocument::pluck('id');
        $this->signIn($user)->deleteJson('/api/cv/'.$ids[0])->assertStatus(204);
        $this->signIn($user)->deleteJson('/api/v1/cv-documents/'.$ids[1])->assertStatus(204);
        $this->assertDatabaseCount('cv_documents', 0);
    }

    public function test_deleting_another_users_cv_document_is_404_on_both(): void
    {
        $owner = $this->makeUser();
        $doc = CvDocument::create([
            'user_id' => $owner->id, 'name' => 'cv.pdf', 'disk_path' => 'cv/1/x.pdf', 'mime' => 'application/pdf',
            'size' => 1, 'extracted_text' => 'text', 'expires_at' => now()->addHour(),
        ]);
        $intruder = $this->makeUser();

        $this->assertSameResponse(
            $this->signIn($intruder)->deleteJson("/api/cv/{$doc->id}"),
            $this->signIn($intruder)->deleteJson("/api/v1/cv-documents/{$doc->id}"),
            'foreign delete'
        );
        $this->assertDatabaseHas('cv_documents', ['id' => $doc->id]);
    }

    public function test_the_analyze_and_improve_cv_endpoints_exist_only_on_legacy_routes(): void
    {
        $user = $this->makeUser();

        $legacy = $this->signIn($user)->postJson('/api/cv/1/analyze', []);
        $this->assertDeprecated($legacy);
        $legacy->assertHeaderMissing('Link');

        $this->assertDeprecated($this->signIn($user)->postJson('/api/ai/improve-cv', []));
        $this->signIn($user)->postJson('/api/v1/cv-documents/1/analyze', [])->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->signIn($user)->postJson('/api/v1/ai/improve-cv', [])->assertStatus(404)->assertJsonPath('code', 'not_found');
    }

    // --- applications -----------------------------------------------------------

    private function applicationPayload(string $url): array
    {
        return ['title' => 'Backend Developer', 'company' => 'Acme', 'url' => $url];
    }

    public function test_application_routes_match(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser();

        $legacy = $this->signIn($user)->postJson('/api/applications', $this->applicationPayload('https://example.test/same'));
        $v1 = $this->signIn($other)->postJson('/api/v1/applications', $this->applicationPayload('https://example.test/same'));
        $this->assertSameResponse($legacy, $v1, 'store');
        $v1->assertStatus(201);
        $this->signIn($user)->postJson('/api/v1/applications', $this->applicationPayload('https://example.test/second'))->assertStatus(201);

        $this->assertSame(2, $this->signIn($user)->getJson('/api/v1/applications')->json('total'));
        $this->assertSame(1, $this->signIn($other)->getJson('/api/applications')->json('total'));

        $legacyId = $legacy->json('id');
        $v1Id = $v1->json('id');
        $this->assertSameResponse(
            $this->signIn($user)->patchJson("/api/applications/{$legacyId}", ['status' => 'interview']),
            $this->signIn($other)->patchJson("/api/v1/applications/{$v1Id}", ['status' => 'interview']),
            'update patch'
        );
        $this->signIn($other)->putJson("/api/v1/applications/{$v1Id}", ['status' => 'offer'])->assertOk()->assertJsonPath('status', 'offer');

        $this->signIn($user)->deleteJson("/api/applications/{$legacyId}")->assertStatus(204);
        $this->signIn($other)->deleteJson("/api/v1/applications/{$v1Id}")->assertStatus(204);
    }

    public function test_application_show_returns_the_owners_record_and_404_for_anyone_else(): void
    {
        $owner = $this->makeUser();
        $application = Application::create(['user_id' => $owner->id] + $this->applicationPayload('https://example.test/show'));

        $this->signIn($owner)->getJson("/api/v1/applications/{$application->id}")
            ->assertOk()
            ->assertJsonPath('id', $application->id)
            ->assertJsonPath('company', 'Acme');

        $intruder = $this->makeUser();
        $this->signIn($intruder)->getJson("/api/v1/applications/{$application->id}")
            ->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->signIn($intruder)->getJson('/api/v1/applications/999999')->assertStatus(404)->assertJsonPath('code', 'not_found');
    }

    public function test_application_show_requires_a_session_and_a_numeric_id(): void
    {
        $this->getJson('/api/v1/applications/1')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->signIn($this->makeUser())->getJson('/api/v1/applications/abc')->assertStatus(404);
    }

    public function test_anonymous_requests_for_a_missing_id_get_401_not_a_404_oracle(): void
    {
        $this->deleteJson('/api/applications/999')->assertStatus(401);
        $this->getJson('/api/v1/applications/999')->assertStatus(401);
        $this->deleteJson('/api/cv/999')->assertStatus(401);
    }

    public function test_the_legacy_applications_show_route_still_does_not_exist(): void
    {
        $owner = $this->makeUser();
        $application = Application::create(['user_id' => $owner->id] + $this->applicationPayload('https://example.test/legacy'));

        $this->signIn($owner)->getJson("/api/applications/{$application->id}")->assertStatus(405);
    }

    // --- jobs -------------------------------------------------------------------

    public function test_job_routes_match(): void
    {
        $user = $this->makeUser();

        $this->assertSameResponse(
            $this->signIn($user)->getJson('/api/jobs/links?q=developer&country=MA'),
            $this->signIn($user)->getJson('/api/v1/jobs/links?q=developer&country=MA'),
            'links'
        );

        $payload = ['name' => 'Remote PHP', 'query' => 'php developer', 'country' => 'MA'];
        $this->assertSameResponse($this->signIn($user)->postJson('/api/jobs/saved-searches', $payload), $this->signIn($user)->postJson('/api/v1/jobs/saved-searches', $payload), 'save');
        $this->assertSameResponse($this->signIn($user)->getJson('/api/jobs/saved-searches'), $this->signIn($user)->getJson('/api/v1/jobs/saved-searches'), 'saved');

        $id = JobSearch::first()->id;
        $this->signIn($user)->deleteJson("/api/v1/jobs/saved-searches/{$id}")->assertStatus(204);

        $this->assertSameResponse($this->signIn($user)->postJson('/api/jobs/search', []), $this->signIn($user)->postJson('/api/v1/jobs/search', []), 'search validation');
    }

    // --- ai and ats -------------------------------------------------------------

    public function test_ai_routes_match(): void
    {
        $user = $this->makeUser();

        $this->assertSameResponse($this->signIn($user)->postJson('/api/ai/chat', ['message' => 'hello']), $this->signIn($user)->postJson('/api/v1/ai/chat', ['message' => 'hello']), 'chat without provider');
        $this->assertSameResponse($this->signIn($user)->postJson('/api/ai/cover-letter', []), $this->signIn($user)->postJson('/api/v1/ai/cover-letter', []), 'cover letter validation');

        Integration::create(['provider' => 'openai', 'type' => 'ai', 'secret' => 'sk-test-secret-value', 'model' => 'gpt-4o-mini', 'enabled' => true, 'priority' => 1]);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'not json']]]])]);
        $body = ['cv_text' => str_repeat('Developer with experience in web applications. ', 3), 'report_format' => 'structured'];
        $this->assertSameResponse($this->signIn($user)->postJson('/api/ai/ats-analysis', $body), $this->signIn($user)->postJson('/api/v1/ai/ats-analysis', $body), 'ats-analysis 502');
    }

    public function test_the_document_review_endpoint_is_unchanged_on_both_paths(): void
    {
        $user = $this->makeUser();
        $cv = "Profile\nDeveloper with several years of experience building web applications for clients.\ncontact@example.test\nExperience\n2024-2025 ACME\n- Developed a client dashboard for 50 customers.\nEducation\nBachelor degree";

        $legacy = $this->signIn($user)->postJson('/api/ats/document', ['cv_text' => $cv]);
        $v1 = $this->signIn($user)->postJson('/api/v1/ats/document', ['cv_text' => $cv]);

        $this->assertSameResponse($legacy, $v1, 'ats/document');
        $legacy->assertOk();
    }

    public function test_every_legacy_route_in_this_group_declares_its_successor(): void
    {
        $user = $this->makeUser();

        $this->assertDeprecated($this->signIn($user)->getJson('/api/cv'), '/api/v1/cv-documents');
        $this->assertDeprecated($this->signIn($user)->getJson('/api/applications'), '/api/v1/applications');
        $this->assertDeprecated($this->signIn($user)->getJson('/api/jobs/saved-searches'), '/api/v1/jobs/saved-searches');
        $this->assertDeprecated($this->signIn($user)->postJson('/api/ai/chat', []), '/api/v1/ai/chat');
        $this->assertDeprecated($this->signIn($user)->postJson('/api/ats/document', []), '/api/v1/ats/document');
    }
}
