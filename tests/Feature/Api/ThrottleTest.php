<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** SPEC §7 item 4: login 5/min per email+IP and 30/min per IP; admin routes 60/min; 429 carries Retry-After. */
class ThrottleTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    private function login(string $email)
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'whatever-123']);
    }

    public function test_the_sixth_login_for_one_email_and_ip_is_429_with_retry_after(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->login('one@example.test')->assertStatus(401);
        }

        $response = $this->login('one@example.test')->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
        $this->assertNotNull($response->headers->get('Retry-After'));
        $this->assertNotNull($response->json('request_id'));
    }

    public function test_other_emails_from_the_same_ip_are_limited_at_30_per_minute(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->login("user{$i}@example.test")->assertStatus(401);
        }

        $this->login('user31@example.test')->assertStatus(429);
    }

    public function test_the_per_email_limit_does_not_block_other_emails(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->login('blocked@example.test')->assertStatus(401);
        }
        $this->login('blocked@example.test')->assertStatus(429);

        $this->login('someone-else@example.test')->assertStatus(401);
    }

    public function test_admin_routes_allow_60_requests_a_minute_across_v1_and_legacy(): void
    {
        $admin = $this->makeAdmin();

        for ($i = 1; $i <= 30; $i++) {
            $this->signIn($admin)->getJson('/api/v1/admin/site-settings')->assertOk();
            $this->signIn($admin)->getJson('/api/admin/site-settings')->assertOk();
        }

        $response = $this->signIn($admin)->getJson('/api/v1/admin/site-settings')->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
        $this->assertNotNull($response->headers->get('Retry-After'));
        $this->signIn($admin)->getJson('/api/admin/site-settings')->assertStatus(429);
    }

    public function test_the_admin_limiter_does_not_apply_to_member_routes_or_other_admins(): void
    {
        $admin = $this->makeAdmin();
        $other = $this->makeAdmin();

        for ($i = 1; $i <= 60; $i++) {
            $this->signIn($admin)->getJson('/api/v1/admin/site-settings')->assertOk();
        }
        $this->signIn($admin)->getJson('/api/v1/admin/site-settings')->assertStatus(429);

        $this->signIn($admin)->getJson('/api/v1/me')->assertOk();
        $this->signIn($other)->getJson('/api/v1/admin/site-settings')->assertOk();
    }

    public function test_register_stays_at_5_per_minute_per_ip(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/v1/auth/register', ['name' => 'A', 'email' => "r{$i}@example.test", 'password' => 'a-long-password-1', 'password_confirmation' => 'a-long-password-1'])->assertStatus(201);
        }

        $this->postJson('/api/v1/auth/register', ['name' => 'A', 'email' => 'r6@example.test', 'password' => 'a-long-password-1', 'password_confirmation' => 'a-long-password-1'])->assertStatus(429);
    }
}
