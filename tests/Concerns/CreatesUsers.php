<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Services\PlatformMail;
use Illuminate\Support\Facades\Hash;

trait CreatesUsers
{
    /** Mails captured instead of sent: [['to' => ..., 'subject' => ..., 'view' => ..., 'data' => [...]]]. */
    protected array $sentMail = [];

    protected function fakePlatformMail(): void
    {
        $this->sentMail = [];
        $sink = function (string $to, string $subject, string $view, array $data) {
            $this->sentMail[] = compact('to', 'subject', 'view', 'data');
        };

        $this->app->instance(PlatformMail::class, new class($sink) extends PlatformMail
        {
            public function __construct(private $sink) {}

            public function send(string $to, string $subject, string $view, array $data): void
            {
                ($this->sink)($to, $subject, $view, $data);
            }
        });
    }

    protected function makeUser(array $attributes = []): User
    {
        static $counter = 0;
        $counter++;

        $user = new User;
        $user->forceFill(array_merge([
            'name' => "Test User {$counter}",
            'email' => "user{$counter}@example.test",
            'password' => Hash::make('correct-horse-battery'),
            'verified_at' => now(),
        ], $attributes));
        $user->save();

        return $user->refresh();
    }

    protected function makeAdmin(array $attributes = []): User
    {
        return $this->makeUser(array_merge(['role' => 'admin'], $attributes));
    }

    /** Sign in the way AuthController::login does: session user_id + session_version. */
    protected function signIn(User $user): static
    {
        return $this->withSession([
            'user_id' => $user->id,
            'session_version' => $user->session_version,
        ]);
    }
}
