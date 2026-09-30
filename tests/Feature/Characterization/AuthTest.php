<?php

namespace Tests\Feature\Characterization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Characterization tests: these pin what the API does TODAY (before the
 * Phase 2 refactor). They are not a statement of desired behavior. Slices
 * S1-S4 change some of these deliberately (SPEC.md section 11) and update the
 * matching assertion in the same commit.
 */
class AuthTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    public function test_register_returns_201_with_a_message_and_no_user(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => ' Ada@Example.TEST ',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertStatus(201)
            ->assertExactJson(['message' => 'Account created. Request your verification code.']);

        $user = User::firstWhere('email', 'ada@example.test');
        $this->assertNotNull($user, 'email is trimmed and lower-cased');
        $this->assertNull($user->verified_at);
        $this->assertSame('user', $user->role);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
    }

    public function test_register_with_an_existing_email_is_422_and_reveals_the_account(): void
    {
        $this->makeUser(['email' => 'taken@example.test']);

        $this->postJson('/api/register', [
            'name' => 'Someone',
            'email' => 'taken@example.test',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['email']]);
    }

    public function test_register_validation_failures_are_422_with_field_errors(): void
    {
        $this->postJson('/api/register', [
            'name' => '',
            'email' => 'not-an-email',
            'password' => 'short',
        ])->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['name', 'email', 'password']]);
    }

    public function test_register_does_not_let_the_client_set_role_or_verification(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Mallory',
            'email' => 'mallory@example.test',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'role' => 'admin',
            'verified_at' => now()->toDateTimeString(),
        ])->assertStatus(201);

        $user = User::firstWhere('email', 'mallory@example.test');
        $this->assertSame('user', $user->role);
        $this->assertNull($user->verified_at);
    }

    public function test_login_success_returns_200_and_the_full_user_model(): void
    {
        $user = $this->makeUser(['email' => 'login@example.test']);

        $response = $this->postJson('/api/login', [
            'email' => 'LOGIN@example.test',
            'password' => self::PASSWORD,
        ])->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('email', 'login@example.test')
            ->assertJsonPath('role', 'user')
            ->assertJsonMissingPath('password');

        // Pinned wart (SPEC 5.8): internal columns are returned.
        $response->assertJsonPath('session_version', 1)
            ->assertJsonPath('suspended', false);

        $this->assertSame($user->id, session('user_id'));
        $this->assertSame(1, session('session_version'));
    }

    public function test_login_with_a_wrong_password_is_401_invalid_credentials(): void
    {
        $this->makeUser(['email' => 'login@example.test']);

        $this->postJson('/api/login', ['email' => 'login@example.test', 'password' => 'wrong-password-123'])
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Invalid credentials.']);
    }

    public function test_login_with_an_unknown_email_is_401_with_the_same_body(): void
    {
        $this->postJson('/api/login', ['email' => 'nobody@example.test', 'password' => self::PASSWORD])
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Invalid credentials.']);
    }

    public function test_login_of_a_suspended_account_is_403_after_the_password_check(): void
    {
        $this->makeUser(['email' => 'suspended@example.test', 'suspended' => true]);

        $this->postJson('/api/login', ['email' => 'suspended@example.test', 'password' => self::PASSWORD])
            ->assertStatus(403)
            ->assertJsonPath('message', 'This account has been suspended by an administrator. Please contact support.');

        $this->postJson('/api/login', ['email' => 'suspended@example.test', 'password' => 'wrong-password-123'])
            ->assertStatus(401);
    }

    public function test_login_of_an_unverified_account_is_403(): void
    {
        $this->makeUser(['email' => 'unverified@example.test', 'verified_at' => null]);

        $this->postJson('/api/login', ['email' => 'unverified@example.test', 'password' => self::PASSWORD])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Verify your email first.');
    }

    public function test_login_validation_is_422(): void
    {
        $this->postJson('/api/login', ['email' => 'bad', 'password' => ''])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['email', 'password']]);
    }

    public function test_every_login_outcome_is_written_to_the_audit_log(): void
    {
        $user = $this->makeUser(['email' => 'audit@example.test']);

        $this->postJson('/api/login', ['email' => 'audit@example.test', 'password' => 'wrong-password-123']);
        $this->postJson('/api/login', ['email' => 'audit@example.test', 'password' => self::PASSWORD]);

        $this->assertDatabaseHas('audit_events', ['event' => 'login_failed', 'user_id' => null]);
        $this->assertDatabaseHas('audit_events', ['event' => 'login', 'user_id' => $user->id]);
    }

    public function test_otp_request_returns_the_same_message_for_known_and_unknown_emails(): void
    {
        $this->fakePlatformMail();
        $this->makeUser(['email' => 'known@example.test', 'verified_at' => null]);

        $known = $this->postJson('/api/otp/request', ['email' => 'known@example.test', 'purpose' => 'verify']);
        $unknown = $this->postJson('/api/otp/request', ['email' => 'unknown@example.test', 'purpose' => 'verify']);

        $known->assertOk()->assertExactJson(['message' => 'If this account exists, a code has been sent.']);
        $unknown->assertOk()->assertExactJson(['message' => 'If this account exists, a code has been sent.']);

        $this->assertCount(1, $this->sentMail, 'mail is only sent to the existing account');
        $this->assertSame('known@example.test', $this->sentMail[0]['to']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->sentMail[0]['data']['code']);
    }

    public function test_otp_request_validates_purpose(): void
    {
        $this->postJson('/api/otp/request', ['email' => 'a@example.test', 'purpose' => 'other'])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['purpose']]);
    }

    public function test_otp_request_is_503_when_mail_delivery_fails(): void
    {
        $this->makeUser(['email' => 'known@example.test', 'verified_at' => null]);
        $this->app->instance(\App\Services\PlatformMail::class, new class extends \App\Services\PlatformMail
        {
            public function __construct() {}

            public function send(string $to, string $subject, string $view, array $data): void
            {
                throw new \RuntimeException('smtp down');
            }
        });

        $this->postJson('/api/otp/request', ['email' => 'known@example.test', 'purpose' => 'verify'])
            ->assertStatus(503)
            ->assertExactJson(['message' => 'Email delivery failed. Try again later.']);
    }

    public function test_otp_verify_marks_the_email_verified_and_the_code_is_single_use(): void
    {
        $this->fakePlatformMail();
        $user = $this->makeUser(['email' => 'verify@example.test', 'verified_at' => null]);
        $this->postJson('/api/otp/request', ['email' => $user->email, 'purpose' => 'verify']);
        $code = $this->sentMail[0]['data']['code'];

        $this->postJson('/api/otp/verify', ['email' => $user->email, 'purpose' => 'verify', 'code' => $code])
            ->assertOk()
            ->assertExactJson(['message' => 'Completed. Please sign in.']);
        $this->assertNotNull($user->refresh()->verified_at);

        $this->postJson('/api/otp/verify', ['email' => $user->email, 'purpose' => 'verify', 'code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Invalid or expired code.');
    }

    public function test_otp_verify_rejects_a_wrong_code_and_locks_after_five_attempts(): void
    {
        $this->fakePlatformMail();
        $user = $this->makeUser(['email' => 'verify@example.test', 'verified_at' => null]);
        $this->postJson('/api/otp/request', ['email' => $user->email, 'purpose' => 'verify']);
        $code = $this->sentMail[0]['data']['code'];
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/otp/verify', ['email' => $user->email, 'purpose' => 'verify', 'code' => $wrong])
                ->assertStatus(422)
                ->assertJsonPath('message', 'Invalid code.');
        }

        // The correct code no longer works after five failures.
        $this->postJson('/api/otp/verify', ['email' => $user->email, 'purpose' => 'verify', 'code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Invalid or expired code.');
        $this->assertNull($user->refresh()->verified_at);
    }

    public function test_otp_verify_for_an_unknown_email_is_422(): void
    {
        $this->postJson('/api/otp/verify', ['email' => 'nobody@example.test', 'purpose' => 'verify', 'code' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Invalid or expired code.');
    }

    public function test_otp_verify_validates_the_code_format(): void
    {
        $this->postJson('/api/otp/verify', ['email' => 'a@example.test', 'purpose' => 'verify', 'code' => 'abc'])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['code']]);
    }

    public function test_password_reset_changes_the_password_and_revokes_sessions(): void
    {
        $this->fakePlatformMail();
        $user = $this->makeUser(['email' => 'reset@example.test']);
        $this->postJson('/api/otp/request', ['email' => $user->email, 'purpose' => 'reset']);
        $code = $this->sentMail[0]['data']['code'];

        $this->postJson('/api/otp/verify', [
            'email' => $user->email, 'purpose' => 'reset', 'code' => $code,
            'password' => 'a-brand-new-passphrase', 'password_confirmation' => 'a-brand-new-passphrase',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('a-brand-new-passphrase', $user->password));
        $this->assertSame(2, $user->session_version);
    }

    public function test_password_reset_requires_a_new_password(): void
    {
        $this->postJson('/api/otp/verify', ['email' => 'a@example.test', 'purpose' => 'reset', 'code' => '123456'])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['password']]);
    }

    public function test_me_returns_the_full_user_model_when_signed_in(): void
    {
        $user = $this->makeUser();

        $this->signIn($user)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('session_version', 1)
            ->assertJsonMissingPath('password');
    }

    public function test_me_without_a_session_is_401_with_an_empty_message(): void
    {
        $this->getJson('/api/me')->assertStatus(401)->assertExactJson(['message' => '']);
    }

    public function test_a_revoked_session_version_is_401(): void
    {
        $user = $this->makeUser();
        $session = ['user_id' => $user->id, 'session_version' => $user->session_version];
        $user->forceFill(['session_version' => 2])->save();

        $this->withSession($session)->getJson('/api/me')->assertStatus(401);
    }

    public function test_suspended_and_unverified_members_are_401_on_member_routes(): void
    {
        $suspended = $this->makeUser(['suspended' => true]);
        $unverified = $this->makeUser(['verified_at' => null]);

        $this->signIn($suspended)->getJson('/api/me')->assertStatus(401);
        $this->signIn($unverified)->getJson('/api/me')->assertStatus(401);
    }

    public function test_a_session_for_a_deleted_user_is_401(): void
    {
        $this->withSession(['user_id' => 999, 'session_version' => 1])->getJson('/api/me')->assertStatus(401);
    }

    public function test_profile_update_changes_only_validated_fields(): void
    {
        $user = $this->makeUser();

        $this->signIn($user)->patchJson('/api/me', [
            'name' => 'New Name',
            'city' => 'Casablanca',
            'country' => 'MA',
            'language' => 'fr',
            'role' => 'admin',
            'email' => 'other@example.test',
        ])->assertOk()->assertJsonPath('name', 'New Name');

        $user->refresh();
        $this->assertSame('Casablanca', $user->city);
        $this->assertSame('fr', $user->language);
        $this->assertSame('user', $user->role, 'role cannot be set through the profile');
        $this->assertNotSame('other@example.test', $user->email, 'email cannot be changed here');
    }

    public function test_profile_validation_is_422(): void
    {
        $this->signIn($this->makeUser())->patchJson('/api/me', ['country' => 'MAR', 'language' => 'de'])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['country', 'language']]);
    }

    public function test_password_change_requires_the_current_password(): void
    {
        $user = $this->makeUser();

        $this->signIn($user)->postJson('/api/password', [
            'current_password' => 'not-the-password',
            'password' => 'another-long-passphrase',
            'password_confirmation' => 'another-long-passphrase',
        ])->assertStatus(422)->assertJsonPath('message', 'Current password is incorrect.');
    }

    public function test_password_change_succeeds_and_bumps_the_session_version(): void
    {
        $user = $this->makeUser();

        $this->signIn($user)->postJson('/api/password', [
            'current_password' => self::PASSWORD,
            'password' => 'another-long-passphrase',
            'password_confirmation' => 'another-long-passphrase',
        ])->assertOk()->assertExactJson(['message' => 'Password changed.']);

        $user->refresh();
        $this->assertTrue(Hash::check('another-long-passphrase', $user->password));
        $this->assertSame(2, $user->session_version);
        $this->assertSame(2, session('session_version'), 'the current session keeps working');
    }

    public function test_logout_returns_200_with_a_message(): void
    {
        $user = $this->makeUser();

        $this->signIn($user)->postJson('/api/logout')
            ->assertStatus(200)
            ->assertExactJson(['message' => 'Signed out.']);

        $this->assertDatabaseHas('audit_events', ['event' => 'logout', 'user_id' => $user->id]);
        $this->assertNull(session('user_id'));
    }

    public function test_logout_without_a_session_is_401(): void
    {
        $this->postJson('/api/logout')->assertStatus(401);
    }

    public function test_cache_used_for_otp_is_the_array_store(): void
    {
        $this->assertSame('array', config('cache.default'));
        Cache::put('probe', 1, 10);
        $this->assertSame(1, Cache::get('probe'));
    }
}
