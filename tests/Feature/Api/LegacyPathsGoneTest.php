<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * S7: the pre-/api/v1 aliases are gone. Every path the old frontend used answers with the standard
 * `not_found` envelope, so a future route cannot quietly bring one back. The Microsoft OAuth callback
 * (`/api/admin/smtp/microsoft/callback`) is not an alias: it is registered with Microsoft and stays.
 */
class LegacyPathsGoneTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> "METHOD path" (relative to /api) as served until S6 */
    public static function removedPaths(): array
    {
        $paths = [
            'GET admin/analytics',
            'GET admin/applications',
            'POST admin/cache/clear',
            'GET admin/contact-messages',
            'PATCH admin/contact-messages/{message}',
            'GET admin/cv-templates',
            'POST admin/cv-templates',
            'DELETE admin/cv-templates/{template}',
            'PATCH admin/cv-templates/{template}',
            'GET admin/integrations',
            'POST admin/integrations',
            'POST admin/integrations/reorder',
            'DELETE admin/integrations/{integration}',
            'PATCH admin/integrations/{integration}',
            'POST admin/integrations/{integration}/test',
            'GET admin/logs',
            'GET admin/site-settings',
            'PUT admin/site-settings',
            'GET admin/smtp',
            'PUT admin/smtp',
            'POST admin/smtp/check',
            'POST admin/smtp/microsoft/connect',
            'POST admin/smtp/test',
            'GET admin/summary',
            'GET admin/system',
            'GET admin/uploads/{cv}',
            'GET admin/users',
            'GET admin/users/{user}',
            'PATCH admin/users/{user}',
            'POST admin/users/{user}/warning',
            'POST ai/ats-analysis',
            'POST ai/chat',
            'POST ai/cover-letter',
            'POST ai/improve-cv',
            'POST analytics/events',
            'GET applications',
            'POST applications',
            'DELETE applications/{application}',
            'PATCH applications/{application}',
            'PUT applications/{application}',
            'POST ats/document',
            'GET career/analytics',
            'POST career/application-pack',
            'GET career/cv-versions',
            'POST career/cv-versions',
            'DELETE career/cv-versions/{version}',
            'GET career/cv-versions/{version}',
            'PATCH career/cv-versions/{version}',
            'GET career/dashboard',
            'POST career/diagnostic',
            'POST career/follow-up',
            'POST career/interviews',
            'DELETE career/interviews/{session}',
            'GET career/interviews/{session}',
            'POST career/interviews/{session}/finish',
            'POST career/interviews/{session}/reply',
            'GET career/library',
            'POST career/portfolio',
            'POST career/recruiter-view',
            'DELETE career/reports/{report}',
            'POST career/skill-gap',
            'POST career/tailor-cv',
            'GET career/workspaces',
            'POST career/workspaces',
            'GET career/workspaces/{workspace}',
            'POST contact',
            'GET csrf',
            'GET cv',
            'POST cv',
            'GET cv-templates',
            'POST cv/extract',
            'DELETE cv/{cv}',
            'POST cv/{cv}/analyze',
            'GET jobs/links',
            'GET jobs/saved-searches',
            'POST jobs/saved-searches',
            'DELETE jobs/saved-searches/{search}',
            'POST jobs/search',
            'POST login',
            'POST logout',
            'DELETE me',
            'GET me',
            'PATCH me',
            'GET me/export',
            'POST otp/request',
            'POST otp/verify',
            'POST password',
            'POST register',
            'GET site-settings',
        ];

        $cases = [];
        foreach ($paths as $entry) {
            [$method, $path] = explode(' ', $entry, 2);
            $cases[$entry] = [$method, $path];
        }

        return $cases;
    }

    #[DataProvider('removedPaths')]
    public function test_a_removed_alias_is_a_404_with_the_envelope(string $method, string $path): void
    {
        $url = '/api/'.preg_replace('/\{\w+\}/', '1', $path);

        // Signed in as an admin so that no auth or role check can answer before the router does.
        $response = $this->signIn($this->makeAdmin())->json($method, $url);

        $response->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->assertNotEmpty($response->json('request_id'));
        $this->assertNull($response->headers->get('Deprecation'));
    }

    public function test_the_oauth_callback_still_exists(): void
    {
        $this->assertSame(
            'api/admin/smtp/microsoft/callback',
            app('router')->getRoutes()->getByName('smtp.microsoft.callback')->uri(),
        );
    }
}
