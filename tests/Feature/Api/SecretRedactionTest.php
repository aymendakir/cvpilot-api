<?php

namespace Tests\Feature\Api;

use App\Models\Integration;
use App\Services\AiGateway;
use App\Services\JobSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** SPEC §7 item 2 pinned end to end: no provider key reaches a response, a stored error or a log file. */
class SecretRedactionTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    private const GEMINI = 'AIzaSyD-FAKE-GEMINI-KEY-0123456789abcdef';

    private const JOOBLE = '0123abcd-4567-89ef-0123-456789abcdef';

    private const OPENAI = 'sk-FAKEOPENAIKEY0123456789';

    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logFile = sys_get_temp_dir().'/cvpilot-redact-'.bin2hex(random_bytes(6)).'.log';
        config(['logging.channels.single.path' => $this->logFile]);
        Log::forgetChannel('single');
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);
        parent::tearDown();
    }

    private function log(): string
    {
        return is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }

    private function assertNoKeys(string $text, string $where): void
    {
        foreach ([self::GEMINI, self::JOOBLE, self::OPENAI] as $key) {
            $this->assertStringNotContainsString($key, $text, "{$where} leaks a provider key");
        }
    }

    public function test_the_gemini_key_goes_in_a_header_never_in_the_url(): void
    {
        $integration = Integration::create(['provider' => 'gemini', 'type' => 'ai', 'secret' => self::GEMINI, 'model' => 'gemini-2.5-flash', 'enabled' => true, 'priority' => 1]);
        $seen = [];
        Http::fake(function ($request) use (&$seen) {
            $seen[] = ['url' => $request->url(), 'key' => $request->header('x-goog-api-key')];

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]]);
        });

        app(AiGateway::class)->test($integration);

        $this->assertNotEmpty($seen);
        foreach ($seen as $call) {
            $this->assertStringNotContainsString(self::GEMINI, $call['url']);
            $this->assertSame([self::GEMINI], $call['key']);
        }
    }

    public function test_a_failed_ai_call_stores_and_logs_no_key(): void
    {
        $user = $this->makeUser();
        Integration::create(['provider' => 'gemini', 'type' => 'ai', 'secret' => self::GEMINI, 'model' => 'gemini-2.5-flash', 'enabled' => true, 'priority' => 1]);
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out for https://generativelanguage.googleapis.com/v1beta/models/m:generateContent?key='.self::GEMINI.' Authorization: Bearer '.self::OPENAI));

        $response = $this->signIn($user)->postJson('/api/v1/ai/chat', ['message' => 'hello']);

        $this->assertContains($response->getStatusCode(), [502, 503]);
        $this->assertNoKeys($response->getContent(), 'response');
        $this->assertNoKeys(json_encode(DB::table('ai_usage')->get()), 'ai_usage');
        $this->assertNoKeys(json_encode(DB::table('integrations')->get(['last_error'])), 'integrations.last_error');
        $this->assertNoKeys($this->log(), 'log file');
    }

    public function test_a_job_provider_failure_stores_and_logs_no_key(): void
    {
        $user = $this->makeUser();
        $this->app->instance(JobSearchService::class, new class extends JobSearchService
        {
            public function search(array $d): array
            {
                throw new ConnectionException('cURL error 6: could not resolve https://jooble.org/api/'.'0123abcd-4567-89ef-0123-456789abcdef');
            }
        });

        $response = $this->signIn($user)->postJson('/api/v1/jobs/search', ['q' => 'php', 'country' => 'DE']);

        $this->assertGreaterThanOrEqual(500, $response->getStatusCode());
        $this->assertNoKeys($response->getContent(), 'response');
        $this->assertNoKeys(json_encode(DB::table('job_search_events')->get()), 'job_search_events');
        $this->assertStringContainsString('jooble.org/api/[REDACTED]', DB::table('job_search_events')->pluck('error')->implode(' '));
        $this->assertNoKeys($this->log(), 'log file');
    }

    public function test_any_unhandled_exception_is_logged_without_keys_but_keeps_its_context(): void
    {
        Route::middleware('web')->get('/api/_t/leak', fn () => throw new \RuntimeException('provider said no: https://api.example.test/v1?api_key='.self::OPENAI.' and key '.self::GEMINI));

        $this->getJson('/api/_t/leak', ['X-Request-Id' => 'trace-redact-1'])->assertStatus(500);

        $log = $this->log();
        $this->assertNoKeys($log, 'log file');
        $this->assertStringContainsString('provider said no', $log);
        $this->assertStringContainsString('trace-redact-1', $log);
        $this->assertStringContainsString('[REDACTED]', $log);
    }
}
