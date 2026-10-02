<?php

namespace Tests\Unit;

use App\Providers\AppServiceProvider;
use App\Support\SessionSecurity;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** SPEC §7 item 1: production never boots with an unsafe session cookie. */
class SessionSecurityTest extends TestCase
{
    public function test_the_default_same_site_is_lax(): void
    {
        $this->assertSame('lax', config('session.same_site'));
    }

    #[DataProvider('unsafeProductionSettings')]
    public function test_production_refuses_unsafe_settings(mixed $secure, mixed $sameSite): void
    {
        $this->expectException(\RuntimeException::class);

        SessionSecurity::assertSafe('production', $secure, $sameSite);
    }

    /** @return array<string, array{0: mixed, 1: mixed}> */
    public static function unsafeProductionSettings(): array
    {
        return [
            'insecure cookie' => [false, 'lax'],
            'insecure cookie with none' => [false, 'none'],
            'unset secure flag' => [null, 'lax'],
            'unknown same_site' => [true, 'sometimes'],
            'disabled same_site' => [true, null],
        ];
    }

    #[DataProvider('safeProductionSettings')]
    public function test_production_accepts_safe_settings(bool $secure, string $sameSite): void
    {
        SessionSecurity::assertSafe('production', $secure, $sameSite);

        $this->assertTrue(true);
    }

    /** @return array<string, array{0: bool, 1: string}> */
    public static function safeProductionSettings(): array
    {
        return ['lax' => [true, 'lax'], 'strict' => [true, 'strict'], 'explicit none' => [true, 'none']];
    }

    public function test_other_environments_are_not_checked(): void
    {
        SessionSecurity::assertSafe('local', false, 'none');
        SessionSecurity::assertSafe('testing', false, null);

        $this->assertTrue(true);
    }

    public function test_the_app_boot_runs_the_check_in_production(): void
    {
        config(['session.secure' => false, 'session.same_site' => 'lax']);
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(\RuntimeException::class);
        (new AppServiceProvider($this->app))->boot();
    }

    public function test_the_app_boots_in_production_with_safe_settings(): void
    {
        config(['session.secure' => true, 'session.same_site' => 'none']);
        $this->app->detectEnvironment(fn () => 'production');

        (new AppServiceProvider($this->app))->boot();

        $this->assertTrue(true);
    }

    // API-B decision D2: with lax/strict every signing-in site must share the API's domain.

    private const HOSTS = [
        'APP_URL' => 'https://api.cvpilottest.online',
        'FRONTEND_URL' => 'https://app.cvpilottest.online',
        'SITE_URL' => 'https://cvpilottest.online',
    ];

    public function test_lax_boots_when_every_host_is_on_the_session_domain(): void
    {
        SessionSecurity::assertSameSiteHosts('production', 'lax', '.cvpilottest.online', self::HOSTS);
        SessionSecurity::assertSameSiteHosts('production', 'lax', null, self::HOSTS);
        SessionSecurity::assertSameSiteHosts('production', 'strict', 'cvpilottest.online', self::HOSTS + ['SITE_URL' => '']);

        $this->assertTrue(true);
    }

    public function test_lax_refuses_a_host_on_another_domain_and_names_the_setting(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('FRONTEND_URL (cvpilot.pages.dev) must be on cvpilottest.online');

        SessionSecurity::assertSameSiteHosts('production', 'lax', '.cvpilottest.online', ['FRONTEND_URL' => 'https://cvpilot.pages.dev'] + self::HOSTS);
    }

    public function test_lax_refuses_a_session_domain_that_does_not_cover_the_api(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('APP_URL (api.cvpilottest.online) must be on cvpilot.example');

        SessionSecurity::assertSameSiteHosts('production', 'lax', '.cvpilot.example', self::HOSTS);
    }

    public function test_a_lookalike_domain_is_not_on_the_domain(): void
    {
        $this->expectException(\RuntimeException::class);

        SessionSecurity::assertSameSiteHosts('production', 'lax', '.cvpilottest.online', ['SITE_URL' => 'https://evilcvpilottest.online'] + self::HOSTS);
    }

    public function test_none_and_other_environments_are_not_checked(): void
    {
        $split = ['FRONTEND_URL' => 'https://cvpilot.pages.dev'] + self::HOSTS;
        SessionSecurity::assertSameSiteHosts('production', 'none', null, $split);
        SessionSecurity::assertSameSiteHosts('local', 'lax', null, $split);

        $this->assertTrue(true);
    }

    public function test_the_app_boot_runs_the_host_check_in_production(): void
    {
        config([
            'session.secure' => true, 'session.same_site' => 'lax', 'session.domain' => '.cvpilottest.online',
            'app.url' => 'https://api.cvpilottest.online', 'mail.frontend_url' => 'https://cvpilot.pages.dev', 'site.url' => '',
        ]);
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(\RuntimeException::class);
        (new AppServiceProvider($this->app))->boot();
    }
}
