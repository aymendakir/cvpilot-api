<?php

namespace Tests\Feature\Api;

use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class UpstreamErrorsTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    private function enableOpenAi(): void
    {
        Integration::create([
            'provider' => 'openai', 'type' => 'ai', 'secret' => 'sk-test-secret-value', 'model' => 'gpt-4o-mini',
            'enabled' => true, 'priority' => 1,
        ]);
    }

    private function chatCompletion(string $content): array
    {
        return ['choices' => [['message' => ['content' => $content]]]];
    }

    public function test_no_enabled_provider_is_503_with_retry_after_and_no_admin_hint(): void
    {
        $response = $this->signIn($this->makeUser())->postJson('/api/ai/chat', ['message' => 'hello'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'upstream_unavailable')
            ->assertHeader('Retry-After', '30');

        $this->assertStringNotContainsString('Admin', (string) $response->json('message'));
        $this->assertStringNotContainsString('API Keys', (string) $response->json('message'));
    }

    public function test_every_provider_failing_is_503_and_the_attempts_are_logged_not_returned(): void
    {
        $this->enableOpenAi();
        Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);

        $response = $this->signIn($this->makeUser())->postJson('/api/ai/chat', ['message' => 'hello'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'upstream_unavailable');

        $this->assertStringNotContainsString('openai', strtolower($response->getContent()));
        $this->assertDatabaseHas('ai_usage', ['provider' => 'openai', 'success' => false]);
    }

    public function test_an_unreachable_provider_is_also_503(): void
    {
        $this->enableOpenAi();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        $this->signIn($this->makeUser())->postJson('/api/ai/chat', ['message' => 'hello'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'upstream_unavailable');
    }

    public function test_an_unparseable_ats_review_is_502_upstream_invalid_response(): void
    {
        $this->enableOpenAi();
        Http::fake(['*' => Http::response($this->chatCompletion('Sorry, I cannot return JSON today.'))]);

        $response = $this->signIn($this->makeUser())->postJson('/api/ai/ats-analysis', [
            'cv_text' => str_repeat('Developer with experience in building web applications. ', 3),
            'report_format' => 'structured',
        ])->assertStatus(502)
            ->assertJsonPath('code', 'upstream_invalid_response')
            ->assertHeaderMissing('Retry-After');

        $this->assertStringNotContainsString('Sorry', $response->getContent());
    }

    public function test_a_french_cv_reviewed_in_english_twice_is_502(): void
    {
        $this->enableOpenAi();
        $english = json_encode([
            'summary' => 'You should improve this CV because the sections from your experience need clearer wording with these changes.',
            'strengths' => [], 'priorities' => [], 'suggestions' => [], 'rubric' => new \stdClass, 'requirements' => [],
        ]);
        Http::fake(['*' => Http::response($this->chatCompletion($english))]);

        $this->signIn($this->makeUser())->postJson('/api/ai/ats-analysis', [
            'cv_text' => "Expérience professionnelle\nDéveloppeur chez Acme depuis 2020 avec des projets pour les clients.\nCompétences\nFormation\nDiplôme d'ingénieur, études supérieures.",
            'report_format' => 'structured',
        ])->assertStatus(502)->assertJsonPath('code', 'upstream_invalid_response');

        Http::assertSentCount(2); // the first answer plus the one language-correction retry
    }

    public function test_a_valid_review_is_unchanged(): void
    {
        $this->enableOpenAi();
        $review = json_encode([
            'summary' => 'A clear CV for a web developer.', 'strengths' => [], 'priorities' => [], 'suggestions' => [],
            'rubric' => new \stdClass, 'requirements' => [],
        ]);
        Http::fake(['*' => Http::response($this->chatCompletion($review))]);

        $this->signIn($this->makeUser())->postJson('/api/ai/ats-analysis', [
            'cv_text' => str_repeat('Developer with experience in building web applications. ', 3),
            'report_format' => 'structured',
        ])->assertOk()->assertJsonPath('review.summary', 'A clear CV for a web developer.');
    }
}
