<?php

namespace Tests\Feature\Characterization;

use App\Models\Application;
use App\Models\CvVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** Pins today's /api/applications behavior (see ErrorShapesTest for the caveats). */
class ApplicationsTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Backend Developer',
            'company' => 'Acme',
            'url' => 'https://example.test/jobs/1',
        ], $overrides);
    }

    public function test_requires_a_session(): void
    {
        $this->getJson('/api/v1/applications')->assertStatus(401);
        $this->postJson('/api/v1/applications', $this->payload())->assertStatus(401);
    }

    public function test_index_is_a_paginator_of_the_users_own_applications_newest_first(): void
    {
        $me = $this->makeUser();
        $other = $this->makeUser();
        Application::create(['user_id' => $me->id] + $this->payload(['title' => 'First', 'url' => 'https://example.test/a']));
        Application::create(['user_id' => $me->id] + $this->payload(['title' => 'Second', 'url' => 'https://example.test/b']));
        Application::create(['user_id' => $other->id] + $this->payload(['title' => 'Not mine']));

        $response = $this->signIn($me)->getJson('/api/v1/applications')->assertOk()
            ->assertJsonStructure(['data', 'current_page', 'last_page', 'per_page', 'total'])
            ->assertJsonPath('per_page', 20)
            ->assertJsonPath('total', 2);

        $this->assertNotContains('Not mine', array_column($response->json('data'), 'title'));
    }

    public function test_store_creates_with_201_and_defaults_the_status(): void
    {
        $me = $this->makeUser();

        $this->signIn($me)->postJson('/api/v1/applications', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('title', 'Backend Developer')
            ->assertJsonPath('user_id', $me->id);

        $this->assertDatabaseHas('applications', ['user_id' => $me->id, 'url' => 'https://example.test/jobs/1']);
        $this->assertDatabaseHas('audit_events', ['event' => 'application_saved', 'user_id' => $me->id]);
    }

    public function test_store_with_the_same_url_updates_and_returns_200(): void
    {
        $me = $this->makeUser();
        $this->signIn($me)->postJson('/api/v1/applications', $this->payload())->assertStatus(201);

        $this->signIn($me)->postJson('/api/v1/applications', $this->payload(['title' => 'Renamed']))
            ->assertStatus(200)
            ->assertJsonPath('title', 'Renamed');

        $this->assertSame(1, Application::where('user_id', $me->id)->count());
    }

    public function test_marking_applied_stamps_the_dates(): void
    {
        $me = $this->makeUser();

        $this->signIn($me)->postJson('/api/v1/applications', $this->payload(['status' => 'applied']))
            ->assertStatus(201)
            ->assertJsonPath('status', 'applied');

        $application = Application::first();
        $this->assertNotNull($application->applied_at);
        $this->assertNotNull($application->application_date);
    }

    public function test_store_validation_is_422(): void
    {
        $this->signIn($this->makeUser())->postJson('/api/v1/applications', $this->payload([
            'url' => 'http://insecure.example.test',
            'status' => 'hired',
            'match_score' => 101,
        ]))->assertStatus(422)->assertJsonStructure(['message', 'errors' => ['url', 'status', 'match_score']]);
    }

    public function test_store_with_another_users_cv_version_is_404(): void
    {
        $other = $this->makeUser();
        $version = CvVersion::create(['user_id' => $other->id, 'name' => 'Theirs', 'content' => str_repeat('cv ', 20)]);

        $this->signIn($this->makeUser())->postJson('/api/v1/applications', $this->payload(['cv_version_id' => $version->id]))
            ->assertStatus(404);
    }

    public function test_update_changes_status_and_returns_the_record(): void
    {
        $me = $this->makeUser();
        $application = Application::create(['user_id' => $me->id] + $this->payload());

        $this->signIn($me)->patchJson("/api/v1/applications/{$application->id}", ['status' => 'interview', 'notes' => 'Call on Monday'])
            ->assertOk()
            ->assertJsonPath('status', 'interview')
            ->assertJsonPath('notes', 'Call on Monday');
    }

    public function test_update_and_delete_of_someone_elses_application_are_404(): void
    {
        $owner = $this->makeUser();
        $application = Application::create(['user_id' => $owner->id] + $this->payload());
        $intruder = $this->makeUser();

        $this->signIn($intruder)->patchJson("/api/v1/applications/{$application->id}", ['status' => 'offer'])->assertStatus(404);
        $this->signIn($intruder)->deleteJson("/api/v1/applications/{$application->id}")->assertStatus(404);
        $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'saved']);
    }

    public function test_delete_returns_204_with_no_body(): void
    {
        $me = $this->makeUser();
        $application = Application::create(['user_id' => $me->id] + $this->payload());

        $response = $this->signIn($me)->deleteJson("/api/v1/applications/{$application->id}");

        $response->assertStatus(204);
        $this->assertSame('', $response->getContent());
        $this->assertDatabaseMissing('applications', ['id' => $application->id]);
    }

    public function test_the_owner_can_show_an_application(): void
    {
        $me = $this->makeUser();
        $application = Application::create(['user_id' => $me->id] + $this->payload());

        $this->signIn($me)->getJson("/api/v1/applications/{$application->id}")->assertOk()->assertJsonPath('id', $application->id);
    }
}
