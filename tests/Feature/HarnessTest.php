<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlatformMail;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class HarnessTest extends TestCase
{
    use CreatesUsers;

    public function test_migrations_run_and_users_can_be_created(): void
    {
        $user = $this->makeUser(['email' => 'harness@example.test']);

        $this->assertSame(1, User::count());
        $this->assertSame('user', $user->role);
        $this->assertNotNull($user->verified_at);
    }

    public function test_me_requires_a_session(): void
    {
        $this->getJson('/api/me')->assertStatus(401);
    }

    public function test_me_returns_the_signed_in_user(): void
    {
        $user = $this->makeUser();

        $this->signIn($user)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('email', $user->email);
    }

    public function test_admin_helper_creates_an_admin(): void
    {
        $admin = $this->makeAdmin();

        $this->signIn($admin)->getJson('/api/admin/summary')->assertOk();
    }

    public function test_mail_is_captured_not_sent(): void
    {
        $this->fakePlatformMail();
        $user = $this->makeUser();

        app(PlatformMail::class)->send($user->email, 'Hello', 'emails.notice', []);

        $this->assertCount(1, $this->sentMail);
        $this->assertSame($user->email, $this->sentMail[0]['to']);
    }
}
