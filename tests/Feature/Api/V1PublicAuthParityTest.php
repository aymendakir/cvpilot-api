<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ComparesRoutes;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** The first /api/v1 routes (public, auth, account) behave like their deprecated legacy twins. */
class V1PublicAuthParityTest extends TestCase
{
    use ComparesRoutes;
    use CreatesUsers;
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    public function test_public_reads_match(): void
    {
        foreach ([
            '/api/csrf' => '/api/v1/csrf',
            '/api/site-settings' => '/api/v1/site-settings',
            '/api/cv-templates' => '/api/v1/cv-templates',
        ] as $legacy => $v1) {
            $legacyResponse = $this->getJson($legacy);
            $v1Response = $this->getJson($v1);

            $this->assertSameResponse($legacyResponse, $v1Response, $v1);
            $this->assertDeprecated($legacyResponse, $v1);
            $this->assertNotDeprecated($v1Response);
        }
    }

    public function test_contact_messages_replaces_contact(): void
    {
        $payload = ['name' => 'V', 'email' => 'v@example.test', 'topic' => 'feedback', 'message' => 'A sufficiently long message.'];

        $legacy = $this->postJson('/api/contact', $payload);
        $v1 = $this->postJson('/api/v1/contact-messages', $payload);

        $this->assertSameResponse($legacy, $v1, 'contact');
        $v1->assertStatus(201);
        $this->assertDeprecated($legacy, '/api/v1/contact-messages');
        $this->assertNotDeprecated($v1);
        $this->assertDatabaseCount('support_messages', 2);
    }

    public function test_analytics_events_match(): void
    {
        $payload = ['consent' => true, 'visitor_id' => str_repeat('a', 20), 'session_id' => str_repeat('b', 20), 'path' => '/'];

        $this->assertSameResponse($this->postJson('/api/analytics/events', $payload), $this->postJson('/api/v1/analytics/events', $payload), 'analytics');
    }

    public function test_register_login_and_otp_match(): void
    {
        $this->fakePlatformMail();

        $register = fn (string $url, string $email) => $this->postJson($url, [
            'name' => 'Ada', 'email' => $email, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ]);
        $this->assertSameResponse($register('/api/register', 'a1@example.test'), $register('/api/v1/auth/register', 'a2@example.test'), 'register');
        $this->assertSameResponse($register('/api/register', 'a1@example.test'), $register('/api/v1/auth/register', 'a2@example.test'), 'register duplicate');

        $this->makeUser(['email' => 'login@example.test']);
        $login = fn (string $url, string $password) => $this->postJson($url, ['email' => 'login@example.test', 'password' => $password]);
        $this->assertSameResponse($login('/api/login', 'wrong-password-123'), $login('/api/v1/auth/login', 'wrong-password-123'), 'login 401');
        $this->assertSameResponse($login('/api/login', self::PASSWORD), $login('/api/v1/auth/login', self::PASSWORD), 'login 200');

        $this->makeUser(['email' => 'otp@example.test', 'verified_at' => null]);
        $otp = fn (string $url) => $this->postJson($url, ['email' => 'otp@example.test', 'purpose' => 'verify']);
        $this->assertSameResponse($otp('/api/otp/request'), $otp('/api/v1/auth/otp/request'), 'otp request');
        $verify = fn (string $url) => $this->postJson($url, ['email' => 'otp@example.test', 'purpose' => 'verify', 'code' => '000000']);
        $this->assertSameResponse($verify('/api/otp/verify'), $verify('/api/v1/auth/otp/verify'), 'otp verify');
    }

    public function test_me_family_matches(): void
    {
        $user = $this->makeUser();

        $this->assertSameResponse($this->signIn($user)->getJson('/api/me'), $this->signIn($user)->getJson('/api/v1/me'), 'me');
        $this->assertSameResponse(
            $this->signIn($user)->patchJson('/api/me', ['city' => 'Rabat']),
            $this->signIn($user)->patchJson('/api/v1/me', ['city' => 'Rabat']),
            'me patch'
        );
        $this->assertSameResponse($this->signIn($user)->getJson('/api/me/export'), $this->signIn($user)->getJson('/api/v1/me/export'), 'export');
        $this->assertSameResponse($this->getJson('/api/me'), $this->getJson('/api/v1/me'), 'me signed out');
    }

    public function test_password_change_is_post_on_legacy_and_put_on_v1(): void
    {
        $user = $this->makeUser();
        $body = fn (string $current) => ['current_password' => $current, 'password' => 'another-long-passphrase', 'password_confirmation' => 'another-long-passphrase'];

        $this->assertSameResponse(
            $this->signIn($user)->postJson('/api/password', $body('nope')),
            $this->signIn($user)->putJson('/api/v1/me/password', $body('nope')),
            'wrong current password'
        );

        $this->signIn($user)->putJson('/api/v1/me/password', $body(self::PASSWORD))->assertOk()->assertExactJson(['message' => 'Password changed.']);
        $this->assertTrue(Hash::check('another-long-passphrase', $user->refresh()->password));
        $this->signIn($user->refresh())->postJson('/api/v1/me/password', $body('another-long-passphrase'))->assertStatus(405);
    }

    public function test_logout_is_204_on_v1_and_still_200_on_legacy(): void
    {
        $user = $this->makeUser();

        $legacy = $this->signIn($user)->postJson('/api/logout');
        $legacy->assertStatus(200)->assertExactJson(['message' => 'Signed out.']);
        $this->assertDeprecated($legacy, '/api/v1/auth/logout');

        $v1 = $this->signIn($user)->postJson('/api/v1/auth/logout');
        $v1->assertStatus(204);
        $this->assertSame('', $v1->getContent());
        $this->assertNotDeprecated($v1);
        $this->assertDatabaseHas('audit_events', ['event' => 'logout', 'user_id' => $user->id]);
    }

    public function test_account_deletion_matches(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        $body = ['current_password' => self::PASSWORD, 'confirmation' => 'DELETE'];

        $legacy = $this->signIn($a)->deleteJson('/api/me', $body);
        $v1 = $this->signIn($b)->deleteJson('/api/v1/me', $body);

        $this->assertSameResponse($legacy, $v1, 'delete account');
        $this->assertNull(User::find($a->id));
        $this->assertNull(User::find($b->id));
    }

    public function test_the_login_throttle_is_one_bucket_shared_by_both_paths(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/login', ['email' => 'nobody@example.test', 'password' => 'whatever-123'])->assertStatus(401);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'whatever-123'])->assertStatus(401);
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'whatever-123'])
            ->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
        $this->postJson('/api/login', ['email' => 'nobody@example.test', 'password' => 'whatever-123'])->assertStatus(429);
    }

    public function test_v1_errors_use_the_same_envelope(): void
    {
        $this->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->getJson('/api/v1/nope')->assertStatus(404)->assertJsonPath('code', 'not_found');
    }
}
