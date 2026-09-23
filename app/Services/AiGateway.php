<?php
namespace App\Services;

use App\Models\Integration;
use Illuminate\Support\Facades\{Http, DB, Log};

class AiGateway {
    public function providers(): array {
        return [
            'openai' => [
                'models' => ['gpt-4o-mini', 'gpt-4o', 'gpt-5-mini', 'gpt-4.1-mini']
            ],
            'groq' => [
                'models' => ['llama-3.3-70b-versatile', 'openai/gpt-oss-120b', 'llama-3.1-8b-instant']
            ],
            'gemini' => [
                'models' => ['gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-1.5-flash']
            ],
            'anthropic' => [
                'models' => ['claude-3-5-sonnet-latest', 'claude-sonnet-4-5', 'claude-haiku-4-5']
            ],
            'mistral' => [
                'models' => ['mistral-small-latest', 'mistral-large-latest']
            ],
            'openrouter' => [
                'models' => ['openai/gpt-4.1-mini', 'anthropic/claude-sonnet-4', 'meta-llama/llama-3.3-70b-instruct']
            ],
            'bazaarlink' => [
                'models' => ['bazaarlink-default']
            ],
        ];
    }

    public function orderedProviders(?string $requestedProvider = null): \Illuminate\Support\Collection {
        $query = Integration::where('type', 'ai')->where('enabled', true);
        if ($requestedProvider) {
            $query->where('provider', $requestedProvider);
        }
        $list = $query->orderBy('priority')->orderBy('id')->get();
        abort_unless($list->isNotEmpty(), 503, 'No enabled AI provider. Configure one in Admin > API Keys.');
        return $list;
    }

    public function active(?string $provider = null): Integration {
        return $this->orderedProviders($provider)->first();
    }

    public function chat(string $prompt, string $feature = 'assistant', ?int $userId = null, ?string $provider = null): array {
        $providers = $this->orderedProviders($provider);
        $lastError = null;
        $attemptErrors = [];

        foreach ($providers as $index => $cfg) {
            $started = microtime(true);
            try {
                $answer = $this->request($cfg, $prompt);
                $latency = (int) ((microtime(true) - $started) * 1000);

                DB::table('ai_usage')->insert([
                    'user_id' => $userId,
                    'provider' => $cfg->provider,
                    'model' => $cfg->model,
                    'feature' => $feature,
                    'latency_ms' => $latency,
                    'success' => true,
                    'created_at' => now(),
                ]);

                if ($cfg->last_error) {
                    $cfg->update(['last_error' => null]);
                }

                return [
                    'answer' => $answer,
                    'provider' => $cfg->provider,
                    'model' => $cfg->model,
                    'fallback_used' => $index > 0,
                    'priority' => $cfg->priority,
                ];
            } catch (\Throwable $e) {
                $latency = (int) ((microtime(true) - $started) * 1000);
                $errorMsg = mb_substr($e->getMessage(), 0, 500);
                $lastError = $e;
                $attemptErrors[] = "{$cfg->provider} (priority {$cfg->priority}): {$errorMsg}";

                DB::table('ai_usage')->insert([
                    'user_id' => $userId,
                    'provider' => $cfg->provider,
                    'model' => $cfg->model,
                    'feature' => $feature,
                    'latency_ms' => $latency,
                    'success' => false,
                    'error' => $errorMsg,
                    'created_at' => now(),
                ]);

                $cfg->update(['last_error' => $errorMsg]);
                Log::warning("AI provider {$cfg->provider} failed, trying fallback: {$errorMsg}");
            }
        }

        throw new \RuntimeException(
            "All available AI providers failed.\n" . implode("\n", $attemptErrors),
            503,
            $lastError
        );
    }

    private function request(Integration $c, string $prompt): string {
        $key = $c->secret;
        $model = $c->model;

        if ($c->provider === 'anthropic') {
            $r = Http::timeout(45)->withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
            ])->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => 1800,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);
            $r->throw();
            return (string) data_get($r->json(), 'content.0.text');
        }

        if ($c->provider === 'gemini') {
            $r = Http::timeout(45)->post(
                'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($key),
                ['contents' => [['parts' => [['text' => $prompt]]]]]
            );
            $r->throw();
            return (string) data_get($r->json(), 'candidates.0.content.parts.0.text');
        }

        $urls = [
            'openai' => 'https://api.openai.com/v1/chat/completions',
            'groq' => 'https://api.groq.com/openai/v1/chat/completions',
            'mistral' => 'https://api.mistral.ai/v1/chat/completions',
            'openrouter' => 'https://openrouter.ai/api/v1/chat/completions',
            'bazaarlink' => 'https://api.bazaarlink.ai/v1/chat/completions',
        ];

        $endpoint = $urls[$c->provider] ?? $urls['openai'];
        $r = Http::timeout(45)->withToken($key)->post($endpoint, [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are CVPilot, a concise career assistant. Never invent candidate experience.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.3,
        ]);
        $r->throw();
        return (string) data_get($r->json(), 'choices.0.message.content');
    }

    public function test(Integration $c): array {
        $answer = $this->request($c, 'Reply with exactly: CVPilot connection ready');
        return ['ok' => true, 'message' => mb_substr($answer, 0, 120)];
    }
}
