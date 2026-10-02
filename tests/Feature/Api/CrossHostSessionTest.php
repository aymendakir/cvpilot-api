<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

/**
 * API-B: one sign-in for app.<domain> and <domain>. The session cookie lives on .<domain>; CORS allows the
 * app and the marketing site with credentials and nobody else.
 */
class CrossHostSessionTest extends TestCase
{
    private const APP = 'https://app.cvpilottest.online';

    private const SITE = 'https://cvpilottest.online';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'session.domain' => '.cvpilottest.online',
            'session.same_site' => 'lax',
            'session.secure' => true,
            'cors.allowed_origins' => [self::APP, self::SITE],
        ]);
    }

    public function test_the_session_cookie_is_shared_by_every_host_of_the_domain(): void
    {
        $cookie = collect($this->getJson('/api/v1/csrf')->assertOk()->headers->getCookies())
            ->firstWhere(fn ($c) => $c->getName() === config('session.cookie'));

        $this->assertNotNull($cookie, 'a session cookie is set');
        $this->assertSame('.cvpilottest.online', $cookie->getDomain());
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
    }

    public function test_the_app_and_the_marketing_site_may_call_with_credentials(): void
    {
        foreach ([self::APP, self::SITE] as $origin) {
            $this->call('OPTIONS', '/api/v1/me', server: [
                'HTTP_ORIGIN' => $origin,
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'x-csrf-token',
            ])
                ->assertHeader('Access-Control-Allow-Origin', $origin)
                ->assertHeader('Access-Control-Allow-Credentials', 'true');

            $this->getJson('/api/v1/me', ['Origin' => $origin])
                ->assertStatus(401)
                ->assertHeader('Access-Control-Allow-Origin', $origin)
                ->assertHeader('Access-Control-Allow-Credentials', 'true');
        }
    }

    public function test_other_origins_get_no_cors_headers(): void
    {
        foreach (['https://evil.example', 'https://evilcvpilottest.online', 'http://app.cvpilottest.online'] as $origin) {
            $this->getJson('/api/v1/me', ['Origin' => $origin])->assertHeaderMissing('Access-Control-Allow-Origin');
        }
    }

    public function test_the_anonymous_routes_still_set_no_cookie(): void
    {
        $response = $this->postJson('/api/v1/public/ats/analyses', ['cv_text' => str_repeat('Built Laravel APIs and reduced costs. ', 5)], ['Origin' => self::SITE]);

        $response->assertOk()->assertHeader('Access-Control-Allow-Origin', self::SITE);
        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_cors_allows_the_site_url_setting(): void
    {
        putenv('SITE_URL=https://marketing.example');
        $_ENV['SITE_URL'] = $_SERVER['SITE_URL'] = 'https://marketing.example';
        try {
            $origins = (require config_path('cors.php'))['allowed_origins'];
        } finally {
            putenv('SITE_URL');
            unset($_ENV['SITE_URL'], $_SERVER['SITE_URL']);
        }

        $this->assertContains('https://marketing.example', $origins);
    }
}
