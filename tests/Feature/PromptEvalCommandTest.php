<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Services\Prompts\AssistantPrompts;
use App\Services\Prompts\PromptEnvelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/** `php artisan cvpilot:prompt-eval` (S6, `SPEC-ats.md` §16.1): the comparison the maintainer reads before turning the envelope on. */
class PromptEvalCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('app/prompt-eval');
        File::deleteDirectory($this->dir);
        Sleep::fake();
        Integration::create(['provider' => 'openai', 'type' => 'ai', 'secret' => 'sk-test-secret-value', 'model' => 'gpt-4o-mini', 'enabled' => true, 'priority' => 1]);
    }

    private function fakeModel(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'Model answer']]]])]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_dry_run_prints_both_prompts_and_calls_no_model(): void
    {
        $this->fakeModel();
        $this->artisan('cvpilot:prompt-eval', ['--dry-run' => true, '--only' => ['tailor_cv']])
            ->expectsOutputToContain('tailor_cv: Tailored CV — current prompt')
            ->expectsOutputToContain('ORIGINAL CV:')
            ->expectsOutputToContain(PromptEnvelope::PREAMBLE)
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertDirectoryDoesNotExist($this->dir);
    }

    public function test_a_run_writes_both_answers_for_every_case(): void
    {
        $this->fakeModel();
        $this->artisan('cvpilot:prompt-eval')->assertSuccessful();

        $files = File::files($this->dir);
        $this->assertCount(1, $files);
        $report = File::get($files[0]->getPathname());
        // 16 cases, each with the current prompt and the envelope.
        Http::assertSentCount(32);
        $this->assertSame(32, substr_count($report, 'Model answer'));
        $this->assertSame(16, substr_count($report, '### Current prompt'));
        $this->assertSame(16, substr_count($report, '### Envelope'));
        $this->assertStringContainsString('## recruiter_view_injection', $report);
        $this->assertDatabaseHas('ai_usage', ['feature' => 'prompt_eval', 'user_id' => null]);
    }

    public function test_both_versions_run_whatever_the_flag_says(): void
    {
        $this->fakeModel();
        config(['ai.prompt_envelope' => true]);
        $this->artisan('cvpilot:prompt-eval', ['--only' => ['skill_gap']])->assertSuccessful();

        $prompts = Http::recorded()->map(fn ($pair) => $pair[0]->data()['messages'][1]['content'])->values();
        $this->assertCount(2, $prompts);
        $this->assertStringNotContainsString(PromptEnvelope::PREAMBLE, $prompts[0]);
        $this->assertStringContainsString(PromptEnvelope::PREAMBLE, $prompts[1]);
    }

    public function test_an_unknown_case_is_refused(): void
    {
        $this->fakeModel();
        $this->artisan('cvpilot:prompt-eval', ['--only' => ['nope']])
            ->expectsOutputToContain('Unknown case: nope')
            ->assertFailed();
        Http::assertNothingSent();
    }

    public function test_a_failed_call_is_written_down_and_the_run_goes_on(): void
    {
        Http::fake(['*' => Http::response(['error' => 'down'], 500)]);
        $this->artisan('cvpilot:prompt-eval', ['--only' => ['chat']])->assertSuccessful();

        $report = File::get(File::files($this->dir)[0]->getPathname());
        $this->assertSame(2, substr_count($report, '**Failed:**'));
        // Each call was tried twice, a minute apart.
        Http::assertSentCount(4);
        Sleep::assertSleptTimes(2);
        $this->assertStringNotContainsString('sk-test-secret-value', $report);
    }

    public function test_every_assistant_has_a_case(): void
    {
        $this->artisan('cvpilot:prompt-eval', ['--list' => true])->assertSuccessful();
        foreach (AssistantPrompts::NAMES as $name) {
            $this->artisan('cvpilot:prompt-eval', ['--dry-run' => true, '--only' => [$name]])->assertSuccessful();
        }
    }

    public function test_calls_are_spaced_by_the_pause(): void
    {
        $this->fakeModel();
        $this->artisan('cvpilot:prompt-eval', ['--only' => ['chat', 'skill_gap'], '--pause' => 30])->assertSuccessful();

        Http::assertSentCount(4);
        // No wait before the first call, then one before each of the three others.
        Sleep::assertSequence([Sleep::for(30)->seconds(), Sleep::for(30)->seconds(), Sleep::for(30)->seconds()]);
    }

    public function test_a_rate_limited_call_is_tried_again_after_the_wait(): void
    {
        Http::fakeSequence()
            ->push(['error' => ['message' => 'Rate limit reached']], 429)
            ->push(['choices' => [['message' => ['content' => 'Second try']]]])
            ->push(['choices' => [['message' => ['content' => 'Envelope answer']]]]);

        $this->artisan('cvpilot:prompt-eval', ['--only' => ['chat'], '--retry-wait' => 45])
            ->expectsOutputToContain('trying again in 45 s')
            ->assertSuccessful();

        $report = File::get(File::files($this->dir)[0]->getPathname());
        $this->assertStringContainsString('Second try', $report);
        $this->assertStringContainsString('Envelope answer', $report);
        $this->assertStringNotContainsString('**Failed:**', $report);
        Sleep::assertSequence([Sleep::for(45)->seconds()]);
    }

    public function test_answers_from_different_models_are_flagged(): void
    {
        Integration::create(['provider' => 'groq', 'type' => 'ai', 'secret' => 'gsk-test-secret-value', 'model' => 'openai/gpt-oss-120b', 'enabled' => true, 'priority' => 0]);
        Http::fake([
            'api.groq.com/*' => Http::sequence()
                ->push(['choices' => [['message' => ['content' => 'From Groq']]]])
                ->push(['error' => ['message' => 'Rate limit reached']], 429),
            '*' => Http::response(['choices' => [['message' => ['content' => 'From OpenAI']]]]),
        ]);

        $this->artisan('cvpilot:prompt-eval', ['--only' => ['chat'], '--retry-wait' => 0])->assertSuccessful();

        $report = File::get(File::files($this->dir)[0]->getPathname());
        $this->assertStringContainsString('**Not comparable:**', $report);
        $this->assertStringContainsString('groq / openai/gpt-oss-120b, openai / gpt-4o-mini', $report);
    }
}
