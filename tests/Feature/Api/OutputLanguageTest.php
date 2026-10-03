<?php

namespace Tests\Feature\Api;

use App\Models\Application;
use App\Models\CareerReport;
use App\Models\Integration;
use App\Models\InterviewSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** The career assistants answer in the language asked for, and in no particular one without it (plan API-C). */
class OutputLanguageTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    private const CV = 'Experienced developer with PHP, Laravel and MySQL experience. Built APIs for clients.';

    private const JOB = 'We are hiring a backend developer to build and maintain Laravel APIs, write tests and review code with the team.';

    private const ROUTES = ['recruiter-view', 'tailor-cv', 'application-pack', 'skill-gap', 'portfolio-review'];

    protected function setUp(): void
    {
        parent::setUp();
        Integration::create(['provider' => 'openai', 'type' => 'ai', 'secret' => 'sk-test-secret-value', 'model' => 'gpt-4o-mini', 'enabled' => true, 'priority' => 1]);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'Réponse']]]])]);
    }

    private function docs(array $extra = []): array
    {
        return ['cv_text' => self::CV, 'job_description' => self::JOB, 'title' => 'Backend', 'company' => 'Acme'] + $extra;
    }

    private function assertPromptSays(string $text): void
    {
        Http::assertSent(fn (Request $request) => str_contains($request->body(), $text));
    }

    public function test_every_assistant_writes_in_the_language_asked_for(): void
    {
        $user = $this->makeUser();

        foreach (self::ROUTES as $route) {
            $this->signIn($user)->postJson("/api/v1/ai/{$route}", $this->docs(['language' => 'French']))->assertOk();
        }
        $this->signIn($user)->postJson('/api/v1/ai/follow-up', ['type' => 'follow_up', 'title' => 'Backend', 'company' => 'Acme', 'language' => 'Spanish'])->assertOk();

        $sent = Http::recorded()->map(fn ($pair) => $pair[0]->body());
        $this->assertCount(6, $sent);
        $this->assertSame(5, $sent->filter(fn ($body) => str_contains($body, 'Write the whole answer in French, headings included.'))->count());
        $this->assertPromptSays('Write the whole answer in Spanish, headings included.');
    }

    public function test_without_a_language_the_prompts_are_unchanged(): void
    {
        $user = $this->makeUser();

        foreach (self::ROUTES as $route) {
            $this->signIn($user)->postJson("/api/v1/ai/{$route}", $this->docs())->assertOk();
        }
        $this->signIn($user)->postJson('/api/v1/ai/follow-up', ['type' => 'follow_up', 'title' => 'Backend', 'company' => 'Acme'])->assertOk();

        Http::assertNotSent(fn (Request $request) => str_contains($request->body(), 'OUTPUT LANGUAGE'));
    }

    public function test_an_unknown_language_is_a_422(): void
    {
        $user = $this->makeUser();

        foreach (self::ROUTES as $route) {
            $this->signIn($user)->postJson("/api/v1/ai/{$route}", $this->docs(['language' => 'Klingon']))
                ->assertStatus(422)->assertJsonValidationErrors(['language']);
        }
        $this->signIn($user)->postJson('/api/v1/ai/follow-up', ['type' => 'follow_up', 'title' => 'B', 'company' => 'A', 'language' => 'fr'])
            ->assertStatus(422)->assertJsonValidationErrors(['language']);
        $this->signIn($user)->postJson('/api/v1/interviews', $this->docs(['language' => 'German']))
            ->assertStatus(422)->assertJsonValidationErrors(['language']);
        $this->signIn($user)->postJson('/api/v1/ai/career-diagnostic', ['language' => 'German'])
            ->assertStatus(422)->assertJsonValidationErrors(['language']);
        Http::assertNothingSent();
    }

    public function test_the_language_is_kept_with_the_saved_result(): void
    {
        $user = $this->makeUser();

        $this->signIn($user)->postJson('/api/v1/ai/skill-gap', $this->docs(['language' => 'Arabic']))->assertOk();

        $this->assertSame('Arabic', CareerReport::where('user_id', $user->id)->sole()->input['language']);
    }

    public function test_an_interview_keeps_its_language_for_every_turn(): void
    {
        $user = $this->makeUser();

        $id = $this->signIn($user)->postJson('/api/v1/interviews', $this->docs(['language' => 'French']))->assertSuccessful()->json('session_id');
        $this->signIn($user)->postJson("/api/v1/interviews/{$id}/reply", ['answer' => "J'ai construit des API."])->assertOk();
        $this->signIn($user)->postJson("/api/v1/interviews/{$id}/finish")->assertOk();

        $this->assertSame('French', InterviewSession::findOrFail($id)->language);
        $this->signIn($user)->getJson("/api/v1/interviews/{$id}")->assertOk()->assertJsonPath('language', 'French');
        $sent = Http::recorded()->map(fn ($pair) => $pair[0]->body());
        $this->assertCount(3, $sent);
        $this->assertTrue($sent->every(fn ($body) => str_contains($body, 'Write the whole answer in French')));
    }

    public function test_an_interview_without_a_language_stays_as_it_was(): void
    {
        $user = $this->makeUser();

        $id = $this->signIn($user)->postJson('/api/v1/interviews', $this->docs())->assertSuccessful()->json('session_id');
        $this->signIn($user)->postJson("/api/v1/interviews/{$id}/reply", ['answer' => 'I built APIs.'])->assertOk();

        $this->assertNull(InterviewSession::findOrFail($id)->language);
        Http::assertNotSent(fn (Request $request) => str_contains($request->body(), 'OUTPUT LANGUAGE'));
    }

    public function test_the_career_diagnostic_answers_in_the_language_asked_for(): void
    {
        $user = $this->makeUser();
        foreach (['applied', 'interview', 'rejected'] as $i => $status) {
            Application::create(['user_id' => $user->id, 'title' => "Role {$i}", 'company' => 'Acme', 'url' => 'https://example.test/job', 'status' => $status]);
        }

        $this->signIn($user)->postJson('/api/v1/ai/career-diagnostic', ['language' => 'French'])->assertOk();

        $this->assertPromptSays('Write the whole answer in French');
    }
}
