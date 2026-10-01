<?php

namespace Tests\Feature;

use App\Support\TrustedProxies;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** S6: HTTPS detection behind a TLS proxy, and the retention heartbeat admins can see. */
class ProxyAndHeartbeatTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        parent::tearDown();
    }

    /** The middleware configuration runs when the HTTP kernel is first resolved, so resolve it before overriding. */
    private function trust(array|string $proxies): void
    {
        $this->app->make(HttpKernel::class);
        TrustProxies::at($proxies);
    }

    public function test_behind_an_untrusted_proxy_forwarded_headers_are_ignored(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->getJson('/api/v1/site-settings', ['X-Forwarded-Proto' => 'https'])->assertOk();

        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_behind_a_trusted_proxy_the_request_counts_as_https_and_hsts_is_sent(): void
    {
        $this->trust('*');

        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->getJson('/api/v1/site-settings', ['X-Forwarded-Proto' => 'https'])->assertOk();

        $this->assertStringContainsString('max-age=31536000', (string) $response->headers->get('Strict-Transport-Security'));
    }

    public function test_a_listed_proxy_is_trusted(): void
    {
        $this->trust(['10.0.0.5']);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->getJson('/api/v1/site-settings', ['X-Forwarded-Proto' => 'https'])->assertHeader('Strict-Transport-Security');
    }

    public function test_a_peer_that_is_not_in_the_list_cannot_claim_https(): void
    {
        $this->trust(['10.0.0.5']);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->getJson('/api/v1/site-settings', ['X-Forwarded-Proto' => 'https'])->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_the_default_configuration_trusts_no_proxy(): void
    {
        $this->assertSame([], TrustedProxies::parse(env('TRUSTED_PROXIES')));
    }

    public function test_admin_system_reports_a_missing_heartbeat_as_unhealthy(): void
    {
        $response = $this->signIn($this->makeAdmin())->getJson('/api/v1/admin/system')->assertOk();

        $response->assertJsonPath('retention.last_run_at', null)->assertJsonPath('retention.healthy', false);
    }

    public function test_a_recent_heartbeat_is_healthy_and_an_old_one_is_not(): void
    {
        $admin = $this->makeAdmin();

        Cache::forever('retention:last_run_at', now()->subMinutes(70)->toIso8601String());
        $this->signIn($admin)->getJson('/api/v1/admin/system')->assertJsonPath('retention.healthy', true);

        Cache::forever('retention:last_run_at', now()->subHours(4)->toIso8601String());
        $this->signIn($admin)->getJson('/api/v1/admin/system')->assertJsonPath('retention.healthy', false)
            ->assertJsonPath('retention.last_run_at', now()->subHours(4)->toIso8601String());
    }

    public function test_the_system_endpoint_stays_admin_only(): void
    {
        $this->getJson('/api/v1/admin/system')->assertStatus(401);
        $this->signIn($this->makeUser())->getJson('/api/v1/admin/system')->assertStatus(403);
    }
}
