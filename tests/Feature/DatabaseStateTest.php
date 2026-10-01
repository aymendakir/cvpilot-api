<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Sevalla has no persistent disk, so production keeps sessions and cache in the database
 * (SESSION_DRIVER=database, CACHE_STORE=database). Local work and the test suite keep the file/array drivers.
 */
class DatabaseStateTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    private function useDatabaseDrivers(): void
    {
        config(['session.driver' => 'database', 'cache.default' => 'database']);
        $this->app['session']->forgetDrivers();
        $this->app['cache']->forgetDriver('database');
        $this->app['cache']->forgetDriver('array');
    }

    private function configValue(string $key, array $env): string
    {
        $process = new Process(['php', 'artisan', 'config:show', $key], base_path(), $env + ['APP_ENV' => 'local']);
        $process->mustRun();

        return $process->getOutput();
    }

    public function test_the_tables_exist(): void
    {
        foreach (['sessions', 'cache', 'cache_locks'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
    }

    public function test_the_defaults_stay_file_based_and_the_env_switches_them(): void
    {
        $this->assertStringContainsString('file', $this->configValue('session.driver', ['SESSION_DRIVER' => false]));
        $this->assertStringContainsString('file', $this->configValue('cache.default', ['CACHE_STORE' => false]));
        $this->assertStringContainsString('database', $this->configValue('session.driver', ['SESSION_DRIVER' => 'database']));
        $this->assertStringContainsString('database', $this->configValue('cache.default', ['CACHE_STORE' => 'database']));
    }

    public function test_a_real_login_survives_across_requests_with_database_sessions(): void
    {
        $this->useDatabaseDrivers();
        $user = $this->makeUser(['email' => 'db.session@example.test']);

        $login = $this->postJson('/api/v1/auth/login', ['email' => 'db.session@example.test', 'password' => 'correct-horse-battery'])->assertOk();
        $cookie = collect($login->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));
        $this->assertNotNull($cookie, 'the session cookie is set');
        $this->assertSame(1, DB::table('sessions')->count(), 'the session is stored in the database, not on disk');

        $this->withUnencryptedCookie(config('session.cookie'), $cookie->getValue())->getJson('/api/v1/me')->assertOk()->assertJsonPath('id', $user->id);
    }

    public function test_logging_out_removes_the_database_session_data(): void
    {
        $this->useDatabaseDrivers();
        $this->makeUser(['email' => 'out@example.test']);
        $login = $this->postJson('/api/v1/auth/login', ['email' => 'out@example.test', 'password' => 'correct-horse-battery'])->assertOk();
        $cookie = collect($login->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));

        $this->withUnencryptedCookie(config('session.cookie'), $cookie->getValue())->postJson('/api/v1/auth/logout')->assertStatus(204);

        $this->withUnencryptedCookie(config('session.cookie'), $cookie->getValue())->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_the_cache_store_holds_values_and_locks_in_the_database(): void
    {
        $this->useDatabaseDrivers();

        Cache::put('otp:test', ['hash' => 'x'], 600);
        Cache::forever('retention:last_run_at', now()->toIso8601String());

        $this->assertSame(['hash' => 'x'], Cache::get('otp:test'));
        $this->assertSame(2, DB::table('cache')->count());
        $lock = Cache::lock('smtp-microsoft-token:1', 30);
        $this->assertTrue($lock->get());
        $this->assertFalse(Cache::lock('smtp-microsoft-token:1', 30)->get(), 'a second holder is refused');
        $lock->release();
        $this->assertTrue(Cache::lock('smtp-microsoft-token:1', 30)->get());
    }

    public function test_rate_limits_and_otp_codes_work_on_the_database_cache(): void
    {
        $this->useDatabaseDrivers();
        $this->fakePlatformMail();
        $user = $this->makeUser(['email' => 'otp@example.test', 'verified_at' => null]);

        $this->postJson('/api/v1/auth/otp/request', ['email' => 'otp@example.test', 'purpose' => 'verify'])->assertOk();
        $this->assertDatabaseHas('cache', ['key' => 'cvpilot'.'otp:verify:'.$user->id]);
        $code = $this->sentMail[0]['data']['code'];
        $this->postJson('/api/v1/auth/otp/verify', ['email' => 'otp@example.test', 'purpose' => 'verify', 'code' => $code])->assertOk();
        $this->assertNotNull($user->refresh()->verified_at);

        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'whatever-123'])->assertStatus(401);
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'whatever-123'])->assertStatus(429);
    }
}
