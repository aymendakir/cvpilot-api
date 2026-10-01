<?php

namespace Tests\Feature\Api;

use App\Http\Resources\AdminUserResource;
use App\Http\Resources\ApplicationResource;
use App\Http\Resources\CvDocumentResource;
use App\Http\Resources\IntegrationResource;
use App\Http\Resources\MailSettingResource;
use App\Http\Resources\ModelResource;
use App\Http\Resources\UserResource;
use App\Models\Application;
use App\Models\CvDocument;
use App\Models\Integration;
use App\Models\MailSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** A response shape is an explicit list: columns that are not on it, and secrets, never leave the API. */
class ResourcesTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    private const POISON = 'POISON-secret-value-0001';

    public function test_user_resources_never_expose_internal_columns(): void
    {
        $user = $this->makeUser(['phone' => '+49 123']);
        $user->forceFill(['password' => self::POISON])->save();

        $self = UserResource::make($user)->resolve();
        $admin = AdminUserResource::make($user)->resolve();

        foreach (['password', 'session_version', 'suspended', 'last_seen_at', 'created_at'] as $key) {
            $this->assertArrayNotHasKey($key, $self, "UserResource must not expose {$key}");
        }
        $this->assertArrayNotHasKey('password', $admin);
        $this->assertArrayNotHasKey('session_version', $admin);
        $this->assertArrayHasKey('suspended', $admin);
        $this->assertSame('+49 123', $self['phone']);
        $this->assertStringNotContainsString(self::POISON, json_encode([$self, $admin]));
    }

    public function test_secret_bearing_models_serialize_without_their_secrets(): void
    {
        $doc = CvDocument::create(['user_id' => $this->makeUser()->id, 'name' => 'cv.pdf', 'disk_path' => self::POISON, 'mime' => 'application/pdf', 'size' => 1, 'extracted_text' => self::POISON, 'expires_at' => now()->addHour()]);
        $integration = Integration::create(['provider' => 'openai', 'type' => 'ai', 'secret' => self::POISON, 'model' => 'm', 'enabled' => true, 'priority' => 1]);
        $mail = MailSetting::create(['host' => 'h', 'port' => 587, 'encryption' => 'tls', 'from_address' => 'a@example.com', 'from_name' => 'n', 'password' => self::POISON, 'oauth_client_secret' => self::POISON, 'oauth_refresh_token' => self::POISON, 'oauth_access_token' => self::POISON, 'auth_mode' => 'password']);

        $json = json_encode([
            CvDocumentResource::make($doc)->resolve(),
            IntegrationResource::make($integration)->resolve(),
            MailSettingResource::make($mail, 'https://example.com/cb')->resolve(),
        ]);

        $this->assertStringNotContainsString(self::POISON, $json);
    }

    public function test_every_resource_only_returns_columns_on_its_field_list(): void
    {
        $user = $this->makeUser();
        $app = Application::create(['user_id' => $user->id, 'title' => 'Dev', 'company' => 'Acme', 'url' => 'https://example.com/j']);
        $app->forceFill(['notes' => 'n'])->save();
        $app->setAttribute('unexpected_internal_flag', true);

        $resolved = ApplicationResource::make($app)->resolve();

        $this->assertArrayNotHasKey('unexpected_internal_flag', $resolved);
        $this->assertArrayHasKey('title', $resolved);
    }

    public function test_no_resource_lists_a_sensitive_field(): void
    {
        $forbidden = ['password', 'secret', 'session_version', 'disk_path', 'extracted_text', 'oauth_client_secret', 'oauth_refresh_token', 'oauth_access_token', 'remember_token'];
        $checked = 0;

        foreach (glob(app_path('Http/Resources/*.php')) as $file) {
            $class = 'App\\Http\\Resources\\'.basename($file, '.php');
            if (! is_subclass_of($class, ModelResource::class) || (new \ReflectionClass($class))->isAbstract()) {
                continue;
            }
            $fields = (new \ReflectionClassConstant($class, 'FIELDS'))->getValue();
            $this->assertSame([], array_values(array_intersect($fields, $forbidden)), "{$class} lists a sensitive field");
            $checked++;
        }

        $this->assertGreaterThan(10, $checked);
    }

    public function test_paginated_lists_keep_the_paginator_shape(): void
    {
        $user = $this->makeUser();
        Application::create(['user_id' => $user->id, 'title' => 'Dev', 'company' => 'Acme', 'url' => 'https://example.com/j']);

        $body = $this->signIn($user)->getJson('/api/v1/applications')->assertOk()->json();

        foreach (['data', 'current_page', 'last_page', 'per_page', 'total'] as $key) {
            $this->assertArrayHasKey($key, $body);
        }
        $this->assertArrayNotHasKey('meta', $body);
        $this->assertSame('Dev', $body['data'][0]['title']);
    }

    public function test_cv_document_lists_never_include_the_extracted_text_or_storage_path(): void
    {
        $user = $this->makeUser();
        CvDocument::create(['user_id' => $user->id, 'name' => 'cv.pdf', 'disk_path' => 'cv/1/secret-path.pdf', 'mime' => 'application/pdf', 'size' => 1, 'extracted_text' => 'PRIVATE CV TEXT', 'expires_at' => now()->addHour()]);

        $content = $this->signIn($user)->getJson('/api/v1/cv-documents')->assertOk()->getContent();

        $this->assertStringNotContainsString('PRIVATE CV TEXT', $content);
        $this->assertStringNotContainsString('secret-path', $content);
    }
}
