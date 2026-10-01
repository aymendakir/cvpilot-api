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
}
