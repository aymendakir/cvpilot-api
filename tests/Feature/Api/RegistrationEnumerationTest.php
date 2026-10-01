<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\PlatformMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** SPEC decision 18.6: register answers the same 201 for new and existing emails. */
class RegistrationEnumerationTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    private const PASSWORD = 'a-long-password-1';

    private function register(string $email, string $url = '/api/v1/auth/register')
    {
        return $this->postJson($url, ['name' => 'Someone', 'email' => $email, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD]);
    }

    public function test_new_and_existing_emails_get_byte_identical_responses(): void
    {
        $this->fakePlatformMail();
        $this->makeUser(['email' => 'taken@example.test']);

        $new = $this->register('fresh@example.test');
        $existing = $this->register('taken@example.test');

        $this->assertSame(201, $new->getStatusCode());
        $this->assertSame($new->getStatusCode(), $existing->getStatusCode());
        $this->assertSame(['message' => 'Account created. Request your verification code.'], $new->json());
        $this->assertSame($new->json(), $existing->json());
        $this->assertSame(array_keys($new->headers->all()), array_keys($existing->headers->all()));
    }

    public function test_an_existing_email_gets_a_notice_and_no_account_is_created_or_changed(): void
    {
        $this->fakePlatformMail();
        $user = $this->makeUser(['email' => 'taken@example.test']);
        $hash = $user->password;

        $this->register('Taken@Example.test')->assertStatus(201);

        $this->assertSame(1, User::count());
        $this->assertSame($hash, $user->refresh()->password);
        $this->assertCount(1, $this->sentMail);
        $this->assertSame('taken@example.test', $this->sentMail[0]['to']);
        $this->assertSame('emails.notice', $this->sentMail[0]['view']);
        $this->assertStringContainsString('already have', $this->sentMail[0]['subject']);
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($this->sentMail));
        $this->assertDatabaseHas('audit_events', ['event' => 'register_existing_email', 'user_id' => $user->id]);
    }

    public function test_a_new_email_creates_the_account_and_sends_no_mail(): void
    {
        $this->fakePlatformMail();

        $this->register('fresh@example.test')->assertStatus(201);

        $this->assertDatabaseHas('users', ['email' => 'fresh@example.test', 'verified_at' => null]);
        $this->assertCount(0, $this->sentMail);
    }

    public function test_at_most_one_notice_per_address_in_ten_minutes(): void
    {
        $this->fakePlatformMail();
        $this->makeUser(['email' => 'taken@example.test']);

        foreach (range(1, 4) as $i) {
            $this->register('taken@example.test')->assertStatus(201);
        }

        $this->assertCount(1, $this->sentMail);

        Cache::flush();
        $this->register('taken@example.test')->assertStatus(201);
        $this->assertCount(2, $this->sentMail);
    }

    public function test_a_mail_failure_does_not_change_the_response(): void
    {
        $this->makeUser(['email' => 'taken@example.test']);
        $this->app->instance(PlatformMail::class, new class extends PlatformMail
        {
            public function send(string $to, string $subject, string $view, array $data): void
            {
                throw new \RuntimeException('smtp down');
            }
        });

        $this->register('taken@example.test')->assertStatus(201)->assertJsonPath('message', 'Account created. Request your verification code.');
    }

    public function test_the_legacy_path_behaves_the_same(): void
    {
        $this->fakePlatformMail();
        $this->makeUser(['email' => 'taken@example.test']);

        $this->register('taken@example.test', '/api/register')->assertStatus(201);

        $this->assertCount(1, $this->sentMail);
    }

    public function test_validation_errors_still_apply_but_never_mention_the_email_being_taken(): void
    {
        $this->makeUser(['email' => 'taken@example.test']);

        $this->postJson('/api/v1/auth/register', ['name' => 'A', 'email' => 'taken@example.test', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertStatus(422)->assertJsonValidationErrors(['password'])->assertJsonMissingValidationErrors(['email']);
    }

    public function test_otp_and_login_answers_do_not_depend_on_the_account_existing(): void
    {
        $this->fakePlatformMail();
        $this->makeUser(['email' => 'real@example.test']);

        $known = $this->postJson('/api/v1/auth/otp/request', ['email' => 'real@example.test', 'purpose' => 'verify']);
        $unknown = $this->postJson('/api/v1/auth/otp/request', ['email' => 'ghost@example.test', 'purpose' => 'verify']);
        $this->assertSame($known->json(), $unknown->json());
        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());

        $wrongPassword = $this->postJson('/api/v1/auth/login', ['email' => 'real@example.test', 'password' => 'nope-nope-nope-1']);
        $noAccount = $this->postJson('/api/v1/auth/login', ['email' => 'ghost@example.test', 'password' => 'nope-nope-nope-1']);
        $this->assertSame($wrongPassword->getStatusCode(), $noAccount->getStatusCode());
        $this->assertSame($wrongPassword->json('code'), $noAccount->json('code'));
        $this->assertSame($wrongPassword->json('message'), $noAccount->json('message'));
    }
}
