<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Services\AiGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** OpenRouter refuses (402) a request without an output limit when the credits cannot cover the model's whole window. */
class AiGatewayLimitsTest extends TestCase
{
    use RefreshDatabase;

    private function answerFrom(string $provider, string $model): void
    {
        Integration::create(['provider' => $provider, 'type' => 'ai', 'secret' => 'test-secret-value', 'model' => $model, 'enabled' => true, 'priority' => 1]);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'Answer']]]])]);
        app(AiGateway::class)->chat('Hello', 'assistant');
    }

    public function test_openrouter_requests_carry_an_output_limit(): void
    {
        $this->answerFrom('openrouter', 'openai/gpt-oss-120b');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'openrouter.ai') && $r->data()['max_tokens'] === 8192);
    }

    public function test_the_limit_comes_from_the_config(): void
    {
        config(['ai.openrouter_max_tokens' => 2048]);
        $this->answerFrom('openrouter', 'openai/gpt-oss-120b');

        Http::assertSent(fn (Request $r) => $r->data()['max_tokens'] === 2048);
    }

    public function test_other_providers_are_unchanged(): void
    {
        $this->answerFrom('groq', 'openai/gpt-oss-120b');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.groq.com') && ! array_key_exists('max_tokens', $r->data()));
    }
}
