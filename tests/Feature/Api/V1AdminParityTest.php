<?php

namespace Tests\Feature\Api;

use App\Models\CvTemplate;
use App\Models\Integration;
use App\Models\SupportMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ComparesRoutes;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** /api/v1/admin routes behave like their legacy twins, with the three documented differences. */
class V1AdminParityTest extends TestCase
{
    use ComparesRoutes;
    use CreatesUsers;
    use RefreshDatabase;

    /** legacy path => v1 path (GET, `admin/analytics` needs MySQL and is covered elsewhere). */
    private const GETS = [
        'admin/summary' => 'admin/summary',
        'admin/users' => 'admin/users',
        'admin/logs' => 'admin/audit-events',
        'admin/system' => 'admin/system',
        'admin/site-settings' => 'admin/site-settings',
        'admin/contact-messages' => 'admin/contact-messages',
        'admin/cv-templates' => 'admin/cv-templates',
        'admin/smtp' => 'admin/smtp',
        'admin/integrations' => 'admin/integrations',
        'admin/applications' => 'admin/applications',
    ];

    public function test_v1_admin_routes_are_401_then_403_then_200(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeAdmin();

        foreach (self::GETS as $v1) {
            $this->getJson("/api/v1/{$v1}")->assertStatus(401);
        }
        foreach (self::GETS as $v1) {
            $this->signIn($user)->getJson("/api/v1/{$v1}")->assertStatus(403)->assertJsonPath('code', 'forbidden');
        }
        // Audit rows accumulate between two requests, so list/summary bodies are compared by shape.
        $counting = ['admin/summary', 'admin/logs', 'admin/users'];
        foreach (self::GETS as $legacy => $v1) {
            $old = $this->signIn($admin)->getJson("/api/{$legacy}");
            $new = $this->signIn($admin)->getJson("/api/v1/{$v1}");
            if (in_array($legacy, $counting, true)) {
                $this->assertSame($old->getStatusCode(), $new->getStatusCode(), $v1);
                $this->assertSame(array_keys($old->json()), array_keys($new->json()), $v1);
            } else {
                $this->assertSameResponse($old, $new, $v1);
            }
        }
    }

    public function test_v1_admin_writes_are_403_for_a_normal_user(): void
    {
        $user = $this->makeUser();
        $target = $this->makeUser();

        $this->signIn($user)->patchJson("/api/v1/admin/users/{$target->id}", ['suspended' => true])->assertStatus(403);
        $this->signIn($user)->putJson('/api/v1/admin/site-settings', [])->assertStatus(403);
        $this->signIn($user)->postJson('/api/v1/admin/integrations', [])->assertStatus(403);
        $this->signIn($user)->deleteJson('/api/v1/admin/cache')->assertStatus(403);
        $this->signIn($user)->postJson("/api/v1/admin/users/{$target->id}/warnings", [])->assertStatus(403);
        $this->assertFalse($target->refresh()->suspended);
    }

    public function test_admin_cache_clear_is_204_on_v1_and_200_with_message_on_legacy(): void
    {
        $admin = $this->makeAdmin();

        $legacy = $this->signIn($admin)->postJson('/api/admin/cache/clear')->assertOk()->assertJsonStructure(['message']);
        $this->assertDeprecated($legacy, '/api/v1/admin/cache');
        $this->signIn($admin)->deleteJson('/api/v1/admin/cache')->assertStatus(204);
    }

    public function test_warning_is_201_on_v1_and_200_on_legacy_with_the_same_body(): void
    {
        $this->fakePlatformMail();
        $admin = $this->makeAdmin();
        $user = $this->makeUser();
        $body = ['subject' => 'Policy reminder', 'message' => 'Please keep uploads professional.', 'severity' => 'notice'];

        $legacy = $this->signIn($admin)->postJson("/api/admin/users/{$user->id}/warning", $body)->assertStatus(200);
        $v1 = $this->signIn($admin)->postJson("/api/v1/admin/users/{$user->id}/warnings", $body)->assertStatus(201);

        $this->assertEquals($legacy->json(), $v1->json());
        $this->assertCount(2, $this->sentMail);
        $this->signIn($admin)->postJson("/api/v1/admin/users/{$user->id}/warnings", [])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    }

    public function test_user_suspension_and_detail_match(): void
    {
        $admin = $this->makeAdmin();
        $a = $this->makeUser();
        $b = $this->makeUser();

        $this->assertSameResponse($this->signIn($admin)->getJson("/api/admin/users/{$a->id}"), $this->signIn($admin)->getJson("/api/v1/admin/users/{$a->id}"), 'detail');
        $legacy = $this->signIn($admin)->patchJson("/api/admin/users/{$a->id}", ['suspended' => true]);
        $v1 = $this->signIn($admin)->patchJson("/api/v1/admin/users/{$b->id}", ['suspended' => true]);
        $this->assertSame($legacy->getStatusCode(), $v1->getStatusCode());
        $this->assertSame($legacy->json('suspended'), $v1->json('suspended'));
        $this->assertTrue($a->refresh()->suspended);
        $this->assertTrue($b->refresh()->suspended);
        $this->signIn($admin)->getJson('/api/v1/admin/users/999999')->assertStatus(404);
    }

    public function test_template_message_and_integration_writes_match(): void
    {
        $admin = $this->makeAdmin();

        $tpl = [
            'name' => 'Modern', 'description' => 'Clean', 'published' => true,
            'design' => ['layout' => 'modern', 'accent' => '#112233', 'font' => 'sans', 'spacing' => 'compact'],
            'sample' => ['name' => 'Ada', 'experience' => [], 'education' => []],
        ];
        $this->assertSameResponse($this->signIn($admin)->postJson('/api/admin/cv-templates', $tpl), $this->signIn($admin)->postJson('/api/v1/admin/cv-templates', $tpl), 'template store');
        $this->assertSameResponse($this->signIn($admin)->postJson('/api/admin/cv-templates', []), $this->signIn($admin)->postJson('/api/v1/admin/cv-templates', []), 'template validation');
        [$a, $b] = CvTemplate::orderBy('id')->pluck('id')->all();
        $this->signIn($admin)->deleteJson("/api/admin/cv-templates/{$a}")->assertStatus(204);
        $this->signIn($admin)->deleteJson("/api/v1/admin/cv-templates/{$b}")->assertStatus(204);

        $message = SupportMessage::create(['name' => 'A', 'email' => 'a@example.com', 'topic' => 'feedback', 'message' => 'A long enough message body.', 'status' => 'new']);
        $this->assertSameResponse($this->signIn($admin)->patchJson("/api/admin/contact-messages/{$message->id}", ['status' => 'read']), $this->signIn($admin)->patchJson("/api/v1/admin/contact-messages/{$message->id}", ['status' => 'read']), 'message update');

        $int = ['provider' => 'openai', 'type' => 'ai', 'secret' => 'sk-super-secret', 'model' => 'gpt-4o-mini', 'enabled' => true];
        $this->assertSameResponse($this->signIn($admin)->postJson('/api/admin/integrations', $int), $this->signIn($admin)->postJson('/api/v1/admin/integrations', $int), 'integration store');
        $this->assertStringNotContainsString('sk-super-secret', $this->signIn($admin)->getJson('/api/v1/admin/integrations')->getContent());
        $id = Integration::first()->id;
        $this->assertSameResponse($this->signIn($admin)->patchJson("/api/admin/integrations/{$id}", ['model' => 'gpt-4o']), $this->signIn($admin)->patchJson("/api/v1/admin/integrations/{$id}", ['model' => 'gpt-4o']), 'integration update');
    }

    public function test_legacy_admin_routes_announce_their_successors(): void
    {
        $admin = $this->makeAdmin();

        $this->assertDeprecated($this->signIn($admin)->getJson('/api/admin/logs'), '/api/v1/admin/audit-events');
        $this->assertDeprecated($this->signIn($admin)->getJson('/api/admin/uploads/5'), '/api/v1/admin/cv-documents/5/file');
        $this->assertDeprecated($this->signIn($admin)->postJson('/api/admin/users/3/warning', []), '/api/v1/admin/users/3/warnings');
        $this->assertNotDeprecated($this->signIn($admin)->getJson('/api/v1/admin/summary'));
    }
}
