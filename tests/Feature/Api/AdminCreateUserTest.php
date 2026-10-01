<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** SPEC §8.2: an admin creates an account (the "Create a user" form in the admin panel). */
class AdminCreateUserTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    private const PASSWORD = 'a-long-password-1';

    private function payload(array $override = []): array
    {
        return $override + [
            'name' => 'New Person', 'email' => 'new.person@example.test', 'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD, 'role' => 'user', 'verified' => false,
        ];
    }

    public function test_only_admins_can_create_accounts(): void
    {
        $this->postJson('/api/v1/admin/users', $this->payload())->assertStatus(401);
        $this->signIn($this->makeUser())->postJson('/api/v1/admin/users', $this->payload())->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'new.person@example.test']);
    }

    public function test_a_member_account_is_created_with_201_and_a_safe_response(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->signIn($admin)->postJson('/api/v1/admin/users', $this->payload(['email' => 'New.Person@Example.Test']))->assertStatus(201);

        $user = User::where('email', 'new.person@example.test')->firstOrFail();
        $this->assertSame('user', $user->role);
        $this->assertNull($user->verified_at);
        $this->assertFalse((bool) $user->suspended);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertNotSame(self::PASSWORD, $user->password);

        $response->assertJsonPath('id', $user->id)->assertJsonPath('email', 'new.person@example.test')->assertJsonPath('role', 'user');
        foreach (['password', 'password_confirmation', 'session_version'] as $key) {
            $response->assertJsonMissingPath($key);
        }
        $this->assertStringNotContainsString(self::PASSWORD, $response->getContent());
    }

    public function test_verified_true_marks_the_email_verified_and_the_person_can_sign_in(): void
    {
        $admin = $this->makeAdmin();

        $this->signIn($admin)->postJson('/api/v1/admin/users', $this->payload(['verified' => true]))->assertStatus(201);
        $this->assertNotNull(User::where('email', 'new.person@example.test')->value('verified_at'));

        $this->flushSession();
        $this->postJson('/api/v1/auth/login', ['email' => 'new.person@example.test', 'password' => self::PASSWORD])->assertOk()->assertJsonPath('email', 'new.person@example.test');
    }

    public function test_an_unverified_account_must_verify_before_signing_in(): void
    {
        $this->signIn($this->makeAdmin())->postJson('/api/v1/admin/users', $this->payload(['verified' => false]))->assertStatus(201);

        $this->flushSession();
        $this->postJson('/api/v1/auth/login', ['email' => 'new.person@example.test', 'password' => self::PASSWORD])
            ->assertStatus(403)->assertJsonPath('code', 'email_not_verified');
    }

    public function test_no_email_is_sent(): void
    {
        $this->fakePlatformMail();

        $this->signIn($this->makeAdmin())->postJson('/api/v1/admin/users', $this->payload())->assertStatus(201);

        $this->assertCount(0, $this->sentMail);
    }

    public function test_an_admin_account_needs_an_explicit_confirmation(): void
    {
        $admin = $this->makeAdmin();
        $body = $this->payload(['role' => 'admin']);

        $this->signIn($admin)->postJson('/api/v1/admin/users', $body)->assertStatus(422)->assertJsonValidationErrors(['confirm_admin']);
        $this->signIn($admin)->postJson('/api/v1/admin/users', $body + ['confirm_admin' => false])->assertStatus(422)->assertJsonValidationErrors(['confirm_admin']);
        $this->assertDatabaseMissing('users', ['email' => 'new.person@example.test']);

        $this->signIn($admin)->postJson('/api/v1/admin/users', $body + ['confirm_admin' => true, 'verified' => true])->assertStatus(201)->assertJsonPath('role', 'admin');
        $this->assertSame('admin', User::where('email', 'new.person@example.test')->value('role'));
    }

    public function test_a_member_does_not_need_the_confirmation_and_unknown_roles_are_rejected(): void
    {
        $admin = $this->makeAdmin();

        $this->signIn($admin)->postJson('/api/v1/admin/users', $this->payload(['confirm_admin' => false]))->assertStatus(201)->assertJsonPath('role', 'user');
        $this->signIn($admin)->postJson('/api/v1/admin/users', $this->payload(['email' => 'x@example.test', 'role' => 'superadmin']))->assertStatus(422)->assertJsonValidationErrors(['role']);
    }

    public function test_the_role_cannot_be_smuggled_in_through_other_fields(): void
    {
        $this->signIn($this->makeAdmin())->postJson('/api/v1/admin/users', $this->payload(['is_admin' => true, 'suspended' => true, 'session_version' => 99, 'id' => 7]))->assertStatus(201);

        $user = User::where('email', 'new.person@example.test')->firstOrFail();
        $this->assertSame('user', $user->role);
        $this->assertFalse((bool) $user->suspended);
        $this->assertSame(1, $user->session_version);
        $this->assertNotSame(7, $user->id);
    }

    public function test_a_duplicate_email_is_a_422_whatever_its_case(): void
    {
        $this->makeUser(['email' => 'taken@example.test']);

        $this->signIn($this->makeAdmin())->postJson('/api/v1/admin/users', $this->payload(['email' => 'TAKEN@example.test']))
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors(['email']);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidInputs(): array
    {
        return [
            'missing everything' => [['name' => null, 'email' => null, 'password' => null, 'role' => null], 'name'],
            'bad email' => [['email' => 'nope'], 'email'],
            'long email' => [['email' => str_repeat('a', 250).'@x.io'], 'email'],
            'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
            'long password' => [['password' => str_repeat('a', 129), 'password_confirmation' => str_repeat('a', 129)], 'password'],
            'password mismatch' => [['password_confirmation' => 'different-pass-22'], 'password'],
            'long name' => [['name' => str_repeat('a', 121)], 'name'],
            'verified not boolean' => [['verified' => 'maybe'], 'verified'],
            'array name' => [['name' => ['x']], 'name'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_is_422_with_the_field_named(array $override, string $field): void
    {
        $this->signIn($this->makeAdmin())->postJson('/api/v1/admin/users', array_filter($this->payload($override), fn ($v) => $v !== null))
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors([$field]);

        $this->assertSame(1, User::count());
    }

    public function test_creation_is_audited_without_the_password(): void
    {
        $admin = $this->makeAdmin();

        $id = $this->signIn($admin)->postJson('/api/v1/admin/users', $this->payload(['role' => 'admin', 'confirm_admin' => true]))->assertStatus(201)->json('id');

        $events = DB::table('audit_events')->where('user_id', $admin->id)->pluck('event')->all();
        $this->assertContains('admin.users.store', $events);
        $this->assertContains("user_created:{$id}", $events);
        $this->assertContains("admin_account_created:{$id}", $events);
        $this->assertStringNotContainsString(self::PASSWORD, json_encode(DB::table('audit_events')->get()));
    }

    public function test_ten_creations_a_minute_at_most(): void
    {
        $admin = $this->makeAdmin();

        foreach (range(1, 10) as $i) {
            $this->signIn($admin)->postJson('/api/v1/admin/users', $this->payload(['email' => "p{$i}@example.test"]))->assertStatus(201);
        }

        $response = $this->signIn($admin)->postJson('/api/v1/admin/users', $this->payload(['email' => 'p11@example.test']))->assertStatus(429);
        $this->assertNotNull($response->headers->get('Retry-After'));
        $this->assertDatabaseMissing('users', ['email' => 'p11@example.test']);
    }
}
