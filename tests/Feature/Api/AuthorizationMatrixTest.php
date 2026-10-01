<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * SPEC §6 test matrix: who gets what on one representative route per scope,
 * on its v1 path. Each case runs in a fresh app (the test client keeps its session).
 */
class AuthorizationMatrixTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: int, 2: string|null}> */
    public static function scopes(): array
    {
        return [
            'public v1' => ['/api/v1/site-settings', 0, null],
            'auth v1' => ['/api/v1/me', 1, null],
            'admin v1' => ['/api/v1/admin/summary', 2, null],
        ];
    }

    /** Expected [status, code] for a role: scope 0 public, 1 signed-in, 2 admin. */
    private function expect(string $role, int $scope): array
    {
        if ($scope === 0) {
            return [200, null];
        }

        return match ($role) {
            'anonymous', 'revoked', 'deleted' => [401, 'unauthenticated'],
            'suspended' => [403, 'account_suspended'],
            'unverified' => [403, 'email_not_verified'],
            'user' => $scope === 1 ? [200, null] : [403, 'forbidden'],
            'admin' => [200, null],
        };
    }

    private function request(string $role, string $path)
    {
        return match ($role) {
            'anonymous' => $this->getJson($path),
            'revoked' => $this->revoked($path),
            'deleted' => $this->withSession(['user_id' => 99999, 'session_version' => 1])->getJson($path),
            'suspended' => $this->signIn($this->makeUser(['suspended' => true]))->getJson($path),
            'unverified' => $this->signIn($this->makeUser(['verified_at' => null]))->getJson($path),
            'user' => $this->signIn($this->makeUser())->getJson($path),
            'admin' => $this->signIn($this->makeAdmin())->getJson($path),
        };
    }

    private function revoked(string $path)
    {
        $user = $this->makeUser();
        $session = ['user_id' => $user->id, 'session_version' => $user->session_version];
        $user->forceFill(['session_version' => $user->session_version + 1])->save();

        return $this->withSession($session)->getJson($path);
    }

    #[DataProvider('scopes')]
    public function test_anonymous_is_401_on_protected_scopes(string $path, int $scope): void
    {
        $this->assertCase('anonymous', $path, $scope);
    }

    #[DataProvider('scopes')]
    public function test_a_revoked_session_is_401(string $path, int $scope): void
    {
        $this->assertCase('revoked', $path, $scope);
    }

    #[DataProvider('scopes')]
    public function test_a_session_for_a_deleted_user_is_401(string $path, int $scope): void
    {
        $this->assertCase('deleted', $path, $scope);
    }

    #[DataProvider('scopes')]
    public function test_a_suspended_account_is_403_account_suspended(string $path, int $scope): void
    {
        $this->assertCase('suspended', $path, $scope);
    }

    #[DataProvider('scopes')]
    public function test_an_unverified_account_is_403_email_not_verified(string $path, int $scope): void
    {
        $this->assertCase('unverified', $path, $scope);
    }

    #[DataProvider('scopes')]
    public function test_a_member_is_forbidden_on_admin_scope_only(string $path, int $scope): void
    {
        $this->assertCase('user', $path, $scope);
    }

    #[DataProvider('scopes')]
    public function test_an_admin_can_use_every_scope(string $path, int $scope): void
    {
        $this->assertCase('admin', $path, $scope);
    }

    public function test_a_suspended_admin_is_blocked_before_the_admin_check(): void
    {
        $admin = $this->makeAdmin(['suspended' => true]);

        $this->signIn($admin)->getJson('/api/v1/admin/summary')->assertStatus(403)->assertJsonPath('code', 'account_suspended');
    }

    public function test_suspending_a_member_revokes_the_open_session_as_a_401(): void
    {
        $admin = $this->makeAdmin();
        $member = $this->makeUser();
        $session = ['user_id' => $member->id, 'session_version' => $member->session_version];

        $this->signIn($admin)->patchJson("/api/v1/admin/users/{$member->id}", ['suspended' => true])->assertOk();

        $this->flushSession();
        $this->withSession($session)->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
    }

    private function assertCase(string $role, string $path, int $scope): void
    {
        [$status, $code] = $this->expect($role, $scope);

        $response = $this->request($role, $path);

        $response->assertStatus($status);
        if ($code !== null) {
            $response->assertJsonPath('code', $code);
        }
    }
}
