<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Services\Prompts\AssistantPrompts;
use App\Services\Prompts\PromptEnvelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
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
        $this->assertStringNotContainsString('sk-test-secret-value', $report);
    }

    public function test_every_assistant_has_a_case(): void
    {
        $this->artisan('cvpilot:prompt-eval', ['--list' => true])->assertSuccessful();
        foreach (AssistantPrompts::NAMES as $name) {
            $this->artisan('cvpilot:prompt-eval', ['--dry-run' => true, '--only' => [$name]])->assertSuccessful();
        }
    }
}
