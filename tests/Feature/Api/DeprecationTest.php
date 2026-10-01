<?php

namespace Tests\Feature\Api;

use App\Services\MicrosoftSmtpOAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class DeprecationTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    public function test_legacy_routes_carry_the_deprecation_header(): void
    {
        $this->getJson('/api/csrf')->assertOk()->assertHeader('Deprecation', 'true');
        $this->getJson('/api/site-settings')->assertOk()->assertHeader('Deprecation', 'true');
        $this->signIn($this->makeUser())->getJson('/api/me')->assertOk()->assertHeader('Deprecation', 'true');
    }

    public function test_the_header_is_also_sent_on_error_responses(): void
    {
        $this->getJson('/api/applications')->assertStatus(401)->assertHeader('Deprecation', 'true');
        $this->postJson('/api/login', [])->assertStatus(422)->assertHeader('Deprecation', 'true');
    }

    public function test_there_is_no_sunset_header_until_a_date_is_configured(): void
    {
        $this->getJson('/api/csrf')->assertHeaderMissing('Sunset');

        config(['api.legacy_sunset' => '2027-03-31']);

        $this->getJson('/api/csrf')->assertHeader('Sunset', 'Wed, 31 Mar 2027 00:00:00 GMT');
    }

    public function test_the_successor_link_is_added_when_a_route_declares_one(): void
    {
        Route::middleware(['web', 'deprecated:/api/v1/things/{thing}'])->get('/api/_t/things/{thing}', fn (string $thing) => ['thing' => $thing]);

        $this->getJson('/api/_t/things/42')->assertOk()
            ->assertHeader('Link', '</api/v1/things/42>; rel="successor-version"');
    }

    public function test_the_oauth_callback_is_permanent_and_not_deprecated(): void
    {
        $route = Route::getRoutes()->getByName('smtp.microsoft.callback');

        $this->assertNotNull($route);
        $this->assertSame('api/admin/smtp/microsoft/callback', $route->uri());
        $this->assertSame(['web', 'throttle:api', 'auth.session', 'admin'], $route->gatherMiddleware());

        config(['app.url' => 'https://api.example.test']);
        $this->assertSame(
            'https://api.example.test/'.$route->uri(),
            app(MicrosoftSmtpOAuth::class)->redirectUri(),
            'the URL registered with Microsoft must match the route'
        );
    }

    public function test_every_legacy_route_has_exactly_one_deprecated_middleware(): void
    {
        foreach (Route::getRoutes() as $route) {
            $isLegacy = str_starts_with($route->uri(), 'api/') && ! str_starts_with($route->uri(), 'api/v1/')
                && $route->getName() !== 'smtp.microsoft.callback';
            $count = count(array_filter($route->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'deprecated')));

            $this->assertSame($isLegacy ? 1 : 0, $count, $route->uri());
        }
    }

    public function test_the_console_page_and_health_route_are_untouched(): void
    {
        $this->get('/')->assertOk()->assertHeaderMissing('Deprecation');
        $this->get('/up')->assertOk()->assertHeaderMissing('Deprecation');
    }

    public function test_every_legacy_route_has_a_unique_legacy_name_and_no_route_is_a_closure(): void
    {
        $names = [];
        foreach (Route::getRoutes() as $route) {
            $isV1 = str_starts_with($route->uri(), 'api/v1/');
            if ($isV1) {
                $this->assertStringStartsWith('v1.', (string) $route->getName(), $route->uri());
            } elseif (str_starts_with($route->uri(), 'api/') && $route->getName() !== 'smtp.microsoft.callback') {
                $this->assertNotNull($route->getName(), $route->uri());
                $this->assertStringStartsWith('legacy.', $route->getName());
                $names[] = $route->getName();
            }
            if ($route->uri() !== 'up') { // the framework's health route is a closure; it is registered by withRouting()
                $this->assertNotSame('Closure', $route->getActionName(), $route->uri());
            }
        }

        $this->assertSame($names, array_values(array_unique($names)), 'route names are unique');
    }
}
