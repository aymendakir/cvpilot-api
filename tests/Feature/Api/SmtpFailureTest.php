<?php

namespace Tests\Feature\Api;

use App\Models\MailSetting;
use App\Services\PlatformMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * SPEC decision 12 (S3): SMTP check/test failures answer 502/503 with a generic body.
 * The redacted reason is stored in mail_settings.last_error, shown to the admin in the
 * settings resource and logged with the request id. No secret ever appears in any of them.
 */
class SmtpFailureTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    private const PASSWORD = 'smtp-secret-pass-123';

    private const TOKEN = 'refresh-token-abcdef-987654';

    private function settings(): MailSetting
    {
        return MailSetting::create([
            'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'mailer@example.com',
            'password' => self::PASSWORD, 'from_address' => 'noreply@example.com', 'from_name' => 'CVPilot',
            'auth_mode' => 'password', 'oauth_refresh_token' => self::TOKEN,
        ]);
    }

    private function failingMail(\Throwable $error): void
    {
        $this->app->instance(PlatformMail::class, new class($error) extends PlatformMail
        {
            public function __construct(private \Throwable $error) {}

            public function checkConnection(): void
            {
                throw $this->error;
            }

            public function send(string $to, string $subject, string $view, array $data): void
            {
                throw $this->error;
            }
        });
    }

    private function assertNoSecret(string $text, string $where): void
    {
        foreach ([self::PASSWORD, self::TOKEN] as $secret) {
            $this->assertStringNotContainsString($secret, $text, "{$where} leaks a secret");
        }
    }

    public function test_an_unreachable_server_is_503_with_a_generic_body_and_a_stored_redacted_reason(): void
    {
        $this->settings();
        $this->failingMail(new \RuntimeException('Connection could not be established with host smtp.example.com: timed out, password='.self::PASSWORD));
        $admin = $this->makeAdmin();

        foreach (['check', 'test'] as $action) {
            $response = $this->signIn($admin)->postJson("/api/v1/admin/smtp/{$action}")->assertStatus(503);

            $this->assertSame('upstream_unavailable', $response->json('code'));
            $this->assertNotNull($response->json('request_id'));
            $this->assertStringNotContainsString('smtp.example.com', $response->getContent(), 'response must stay generic');
            $this->assertNoSecret($response->getContent(), "{$action} response");
        }

        $stored = (string) MailSetting::first()->last_error;
        $this->assertNotSame('', $stored);
        $this->assertNoSecret($stored, 'stored last_error');
        $this->assertLessThanOrEqual(500, mb_strlen($stored));

        $shown = $this->signIn($admin)->getJson('/api/v1/admin/smtp')->assertOk();
        $this->assertSame($stored, $shown->json('last_error'));
        $this->assertNoSecret($shown->getContent(), 'admin settings resource');
        $this->assertTrue($shown->json('has_password'));
        $this->assertArrayNotHasKey('password', $shown->json());
    }

    public function test_a_server_that_rejects_us_is_502(): void
    {
        $this->settings();
        $this->failingMail(new \RuntimeException('Expected response code "235" but got code "535", with message "535 5.7.8 Authentication failed for '.self::PASSWORD.'".'));

        $response = $this->signIn($this->makeAdmin())->postJson('/api/v1/admin/smtp/check')->assertStatus(502);

        $this->assertSame('upstream_invalid_response', $response->json('code'));
        $this->assertNoSecret($response->getContent(), 'response');
        $this->assertNoSecret((string) MailSetting::first()->last_error, 'stored last_error');
    }

    public function test_the_failure_is_logged_with_the_request_id_and_without_secrets(): void
    {
        $this->settings();
        $this->failingMail(new \RuntimeException('Connection could not be established: '.self::PASSWORD));
        $logged = [];
        Log::swap(new class($logged)
        {
            public function __construct(public array &$entries) {}

            public function __call($method, $args)
            {
                $this->entries[] = [$method, $args];

                return null;
            }
        });

        $response = $this->signIn($this->makeAdmin())->postJson('/api/v1/admin/smtp/test')->assertStatus(503);

        $entries = array_filter($logged, fn ($e) => ($e[1][0] ?? '') === 'SMTP operation failed');
        $this->assertCount(1, $entries);
        $context = array_values($entries)[0][1][1];
        $this->assertSame($response->json('request_id'), $context['request_id']);
        $this->assertSame('send', $context['operation']);
        $this->assertNoSecret(json_encode($logged), 'log');
    }

    public function test_a_successful_check_clears_the_stored_reason(): void
    {
        $this->settings()->update(['last_error' => 'old failure']);
        $this->app->instance(PlatformMail::class, new class extends PlatformMail
        {
            public function checkConnection(): void {}
        });

        $this->signIn($this->makeAdmin())->postJson('/api/v1/admin/smtp/check')->assertOk();

        $this->assertNull(MailSetting::first()->last_error);
    }

    public function test_our_own_php_errors_are_a_generic_500_but_still_store_a_reason(): void
    {
        $this->settings();
        $this->failingMail(new \Error('Call to undefined function '.self::PASSWORD.'()'));

        $response = $this->signIn($this->makeAdmin())->postJson('/api/v1/admin/smtp/test')->assertStatus(500);

        $this->assertSame('server_error', $response->json('code'));
        $this->assertNoSecret($response->getContent(), 'response');
        $this->assertStringContainsString('PHP runtime error', (string) MailSetting::first()->last_error);
        $this->assertNoSecret((string) MailSetting::first()->last_error, 'stored last_error');
    }
}
