<?php

namespace Tests\Feature\Api;

use App\Services\Ats\AtsAnalyzer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Anonymous ATS check and file reading (Phase 4 API-A): same answers as the signed-in routes, no cookie,
 * nothing stored or logged, per-visitor and global limits, Turnstile before the upload is read, and
 * `errors.file` tokens on the file-reading route.
 */
class PublicAccessTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    private const DIR = __DIR__.'/../../fixtures/ats';

    private const ATS = '/api/v1/public/ats/analyses';

    private const EXTRACT = '/api/v1/public/cv/extract';

    private const SENTINEL = 'Zq7Sentinel4471';

    private const SECRET = '1x0000000000000000000000000000000AA';

    private function file(string $path, ?string $name = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name ?? basename($path), (string) file_get_contents(self::DIR."/{$path}"));
    }

    private function send(string $url, array $body, array $headers = [], string $ip = '203.0.113.7')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->call('POST', $url, $body, [], array_filter($body, fn ($v) => $v instanceof UploadedFile), $this->transformHeadersToServerVars(['Accept' => 'application/json'] + $headers));
    }

    private function withTurnstile(array $answer = ['success' => true, 'hostname' => 'cvpilottest.online']): void
    {
        config(['anonymous.turnstile.secret' => self::SECRET, 'anonymous.turnstile.hostnames' => ['cvpilottest.online']]);
        Http::fake(['challenges.cloudflare.com/*' => Http::response($answer)]);
    }

    // T1: routes, no session, same answers ------------------------------------------------------

    public function test_the_anonymous_check_returns_the_same_report_as_the_signed_in_route(): void
    {
        $public = $this->send(self::ATS, AtsAnalysesTest::body('cvs/clean-en.docx', 'jobs/laravel-dev.txt'))->assertOk()->json();
        $account = $this->signIn($this->makeUser())->post('/api/v1/ats/analyses', AtsAnalysesTest::body('cvs/clean-en.docx', 'jobs/laravel-dev.txt'), ['Accept' => 'application/json'])->assertOk()->json();
        unset($public['generated_at'], $account['generated_at']);

        $this->assertSame($account, $public);
    }

    public function test_both_routes_set_no_cookie(): void
    {
        foreach ([[self::ATS, AtsAnalysesTest::body('cvs/no-email-no-exp.txt')], [self::EXTRACT, ['file' => $this->file('cvs/clean-en.docx')]]] as [$url, $body]) {
            $response = $this->post($url, $body)->assertOk();
            $this->assertSame([], $response->headers->getCookies(), $url);
            $this->assertFalse($response->headers->has('Set-Cookie'), $url);
        }
    }

    public function test_the_anonymous_routes_need_no_csrf_token(): void
    {
        // The test client sends no X-CSRF-TOKEN; the signed-in route would answer 419 csrf_mismatch outside tests.
        $this->send(self::EXTRACT, ['file' => $this->file('cvs/clean-en.docx')])
            ->assertOk()
            ->assertJsonPath('text', fn ($text) => is_string($text) && str_contains($text, 'Experience'));
    }

    // T1: limits --------------------------------------------------------------------------------

    public function test_the_per_minute_limit_answers_429_with_retry_after(): void
    {
        config(['anonymous.limits.ats_per_minute' => 2]);
        $body = fn () => AtsAnalysesTest::body('cvs/no-email-no-exp.txt');

        $this->send(self::ATS, $body())->assertOk();
        $this->send(self::ATS, $body())->assertOk();
        $this->send(self::ATS, $body())->assertStatus(429)->assertJsonPath('code', 'too_many_requests')->assertHeader('Retry-After');
        $this->send(self::ATS, $body(), ip: '198.51.100.9')->assertOk();
    }

    public function test_the_daily_and_global_limits_apply(): void
    {
        config(['anonymous.limits.ats_per_minute' => 100, 'anonymous.limits.ats_per_day' => 2, 'anonymous.limits.ats_global_per_day' => 3]);
        $body = fn () => AtsAnalysesTest::body('cvs/no-email-no-exp.txt');

        $this->send(self::ATS, $body())->assertOk();
        $this->send(self::ATS, $body())->assertOk();
        $this->send(self::ATS, $body())->assertStatus(429);
        $this->send(self::ATS, $body(), ip: '198.51.100.9')->assertOk();
        $this->send(self::ATS, $body(), ip: '192.0.2.44')->assertStatus(429, 'the global cap counts every visitor');
    }

    public function test_the_file_reading_limit_is_separate(): void
    {
        config(['anonymous.limits.extract_per_minute' => 1]);

        $this->send(self::EXTRACT, ['file' => $this->file('cvs/clean-en.docx')])->assertOk();
        $this->send(self::EXTRACT, ['file' => $this->file('cvs/clean-en.docx')])->assertStatus(429);
        $this->send(self::ATS, AtsAnalysesTest::body('cvs/no-email-no-exp.txt'))->assertOk();
    }

    public function test_cf_connecting_ip_is_used_only_when_trusted(): void
    {
        config(['anonymous.limits.extract_per_minute' => 1]);
        $cf = fn (string $ip) => ['CF-Connecting-IP' => $ip];

        // Not trusted: a forged header does not give a new bucket.
        $this->send(self::EXTRACT, ['file' => $this->file('cvs/clean-en.docx')], $cf('198.51.100.1'))->assertOk();
        $this->send(self::EXTRACT, ['file' => $this->file('cvs/clean-en.docx')], $cf('198.51.100.2'))->assertStatus(429);

        // Trusted (origin reachable through Cloudflare only): each visitor has their own bucket.
        config(['anonymous.trust_cf_connecting_ip' => true]);
        $this->send(self::EXTRACT, ['file' => $this->file('cvs/clean-en.docx')], $cf('198.51.100.3'), '162.158.0.1')->assertOk();
        $this->send(self::EXTRACT, ['file' => $this->file('cvs/clean-en.docx')], $cf('198.51.100.4'), '162.158.0.1')->assertOk();
        $this->send(self::EXTRACT, ['file' => $this->file('cvs/clean-en.docx')], $cf('198.51.100.4'), '162.158.0.2')->assertStatus(429);
    }

    public function test_rate_limit_keys_never_hold_the_address(): void
    {
        $request = Request::create(self::ATS, 'POST', server: ['REMOTE_ADDR' => '203.0.113.77']);
        $keys = collect(array_merge((array) RateLimiter::limiter('public-ats')($request), (array) RateLimiter::limiter('public-extract')($request)))
            ->map(fn (Limit $limit) => $limit->key)
            ->implode(' ');

        $this->assertStringContainsString('public-ats-minute:', $keys);
        $this->assertStringNotContainsString('203.0.113.77', $keys);
    }

    // T2: Turnstile -----------------------------------------------------------------------------

    public function test_a_valid_token_is_checked_with_cloudflare_before_the_report(): void
    {
        $this->withTurnstile();

        $this->send(self::ATS, AtsAnalysesTest::body('cvs/no-email-no-exp.txt') + ['cf-turnstile-response' => 'token-1'])->assertOk();
        $this->send(self::EXTRACT, ['file' => $this->file('cvs/clean-en.docx')], ['CF-Turnstile-Response' => 'token-2'])->assertOk();

        Http::assertSentCount(2);
        Http::assertSent(fn (ClientRequest $r) => $r['secret'] === self::SECRET && $r['response'] === 'token-1' && $r['remoteip'] === '203.0.113.7');
    }

    public function test_missing_failed_and_foreign_tokens_are_refused_with_a_token(): void
    {
        config(['anonymous.turnstile.secret' => self::SECRET, 'anonymous.turnstile.hostnames' => ['cvpilottest.online']]);
        Http::fake(['challenges.cloudflare.com/*' => Http::sequence()
            ->push(['success' => false, 'error-codes' => ['invalid-input-response']])
            ->push(['success' => true, 'hostname' => 'evil.example'])]);
        $body = fn (array $extra = []) => AtsAnalysesTest::body('cvs/no-email-no-exp.txt') + $extra;

        $this->send(self::ATS, $body())
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonPath('errors.turnstile', ['missing']);
        $this->send(self::ATS, $body(['cf-turnstile-response' => 'bad']))->assertStatus(422)->assertJsonPath('errors.turnstile', ['failed']);
        $this->send(self::ATS, $body(['cf-turnstile-response' => 'elsewhere']))->assertStatus(422)->assertJsonPath('errors.turnstile', ['failed']);
        Http::assertSentCount(2);
    }

    public function test_cloudflare_unreachable_is_503(): void
    {
        config(['anonymous.turnstile.secret' => self::SECRET]);
        Http::fake(fn () => throw new ConnectionException('timed out'));
        $this->send(self::ATS, AtsAnalysesTest::body('cvs/no-email-no-exp.txt') + ['cf-turnstile-response' => 't'])
            ->assertStatus(503)->assertJsonPath('code', 'upstream_unavailable');

        Http::fake(['challenges.cloudflare.com/*' => Http::response('oops', 500)]);
        $this->send(self::ATS, AtsAnalysesTest::body('cvs/no-email-no-exp.txt') + ['cf-turnstile-response' => 't'])->assertStatus(503);
    }

    public function test_production_without_a_secret_refuses(): void
    {
        $this->app['env'] = 'production';
        config(['anonymous.turnstile.secret' => null]);

        $this->send(self::ATS, AtsAnalysesTest::body('cvs/no-email-no-exp.txt'))->assertStatus(503)->assertJsonPath('code', 'upstream_unavailable');
        $this->send(self::EXTRACT, ['file' => $this->file('cvs/clean-en.docx')])->assertStatus(503);
    }

    public function test_the_upload_is_not_read_before_turnstile_passes(): void
    {
        $this->withTurnstile(['success' => false]);
        // AtsAnalyzer is final: fail loudly if the controller even resolves it.
        $this->app->bind(AtsAnalyzer::class, fn () => throw new \LogicException('the upload was read before Turnstile passed'));

        $this->send(self::ATS, ['file' => $this->file('cvs/clean-en.pdf'), 'cf-turnstile-response' => 'bad'])->assertStatus(422);
    }

    public function test_the_secret_and_the_token_are_never_logged(): void
    {
        $this->withTurnstile(['success' => false, 'error-codes' => ['timeout-or-duplicate']]);
        $logged = $this->captureLogs();

        $this->send(self::ATS, AtsAnalysesTest::body('cvs/no-email-no-exp.txt') + ['cf-turnstile-response' => self::SENTINEL])->assertStatus(422);

        $all = (string) json_encode($logged->getArrayCopy());
        $this->assertStringContainsString('timeout-or-duplicate', $all);
        $this->assertStringNotContainsString(self::SECRET, $all);
        $this->assertStringNotContainsString(self::SENTINEL, $all);
    }

    // T3: errors and privacy --------------------------------------------------------------------

    public function test_file_reading_errors_are_tokens(): void
    {
        $cases = [
            'unsupported_type' => ['file' => $this->file('invalid/legacy.doc')],
            'password_protected' => ['file' => $this->file('invalid/encrypted.pdf')],
            'corrupt' => ['file' => $this->file('invalid/corrupt.pdf')],
            'no_text' => ['file' => UploadedFile::fake()->createWithContent('short.txt', 'Too short.')],
            'missing' => [],
            'too_large' => ['file' => UploadedFile::fake()->create('big.pdf', 16 * 1024)],
        ];
        foreach ($cases as $token => $body) {
            $this->send(self::EXTRACT, $body)->assertStatus(422)->assertJsonPath('errors.file', [$token]);
        }
    }

    public function test_the_signed_in_file_reading_route_keeps_its_messages(): void
    {
        $this->signIn($this->makeUser())
            ->post('/api/v1/cv-documents/extract', ['file' => $this->file('invalid/encrypted.pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This PDF is password protected. Upload an unlocked copy.');
    }

    public function test_the_ats_route_returns_the_same_file_tokens(): void
    {
        $this->send(self::ATS, ['file' => $this->file('invalid/encrypted.pdf')])->assertStatus(422)->assertJsonPath('errors.file', ['password_protected']);
    }

    public function test_nothing_is_kept_and_no_content_or_address_is_logged(): void
    {
        $cvText = "Samir Benali\n".self::SENTINEL."@example.com\nWork Experience\nBuilt Laravel APIs used by ".self::SENTINEL." customers.\n".str_repeat('Reduced costs by 20% with careful PHP work. ', 10);
        $job = "Requirements:\nPHP\nLaravel\n".self::SENTINEL."\nDocker\nNice to have:\nRedis and a team that ships every week.";
        $logged = $this->captureLogs();
        $rows = $this->rows();

        $this->send(self::ATS, ['cv_text' => $cvText, 'job_description' => $job], ip: '203.0.113.99')->assertOk();
        $this->send(self::ATS, ['file' => $this->file('cvs/two-column.pdf', self::SENTINEL.'-cv.pdf')], ip: '203.0.113.99')->assertOk();
        $this->send(self::EXTRACT, ['file' => $this->file('cvs/clean-en.docx', self::SENTINEL.'-cv.docx')], ip: '203.0.113.99')->assertOk();

        $this->assertSame($rows, $this->rows(), 'no row stored');
        $all = (string) json_encode($logged->getArrayCopy(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString(self::SENTINEL, $all, 'no CV text, job text or file name in the logs');
        $this->assertStringNotContainsString('203.0.113.99', $all, 'no address in the logs');
        $lines = array_values(array_filter($logged->getArrayCopy(), fn ($l) => $l['message'] === 'ats.analysis'));
        $this->assertCount(2, $lines);
        foreach ($lines as $line) {
            $this->assertSame('public', $line['context']['access']);
            $this->assertArrayNotHasKey('score', $line['context']);
        }
    }

    /** @return array<string, int> rows per table, except the rate-limiter cache */
    private function rows(): array
    {
        $rows = [];
        foreach (DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'") as $table) {
            if (! in_array($table->name, ['cache', 'cache_locks'], true)) {
                $rows[$table->name] = DB::table($table->name)->count();
            }
        }

        return $rows;
    }

    /** Production may run at LOG_LEVEL=info: capture at that level. */
    private function captureLogs(): \ArrayObject
    {
        $channel = (string) config('logging.default');
        config(["logging.channels.{$channel}.level" => 'info']);
        Log::forgetChannel($channel);
        $logged = new \ArrayObject;
        Event::listen(MessageLogged::class, function (MessageLogged $e) use ($logged) {
            $logged[] = ['message' => $e->message, 'context' => $e->context];
        });

        return $logged;
    }
}
