<?php

namespace Tests\Feature\Api;

use App\Models\BlogPost;
use Illuminate\Routing\Route as RouteObject;
use Illuminate\Support\Facades\Route;
use Tests\Support\RouteDocs;
use Tests\TestCase;

/** The /api/v1 route table is a contract: SPEC §3.2 (pinned in tests/fixtures/routes-v1.json). */
class V1RoutesContractTest extends TestCase
{
    private function pinned(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/fixtures/routes-v1.json')), true);
    }

    public function test_the_v1_route_table_equals_the_pinned_contract(): void
    {
        $actual = [];
        foreach (RouteDocs::v1() as $route) {
            foreach (RouteDocs::methods($route) as $method) {
                $actual[] = ['method' => $method, 'uri' => substr($route->uri(), 4), 'scope' => RouteDocs::scope($route)];
            }
        }
        $key = fn ($r) => "{$r['scope']} {$r['method']} {$r['uri']}";
        $expected = array_map($key, $this->pinned());
        $actual = array_map($key, $actual);
        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual, 'v1 routes differ from tests/fixtures/routes-v1.json (update the fixture only with SPEC §3.2)');
    }

    public function test_every_v1_route_is_named_controller_based_and_has_numeric_ids(): void
    {
        foreach (RouteDocs::v1() as $route) {
            $label = implode('|', RouteDocs::methods($route)).' '.$route->uri();

            $this->assertStringStartsWith('v1.', (string) $route->getName(), "{$label}: route name");
            $this->assertFalse($route->getAction('uses') instanceof \Closure, "{$label}: closure route");

            preg_match_all('/\{(\w+)\}/', $route->uri(), $params);
            foreach ($params[1] as $param) {
                // Blog slugs use the slug pattern; every other parameter is a numeric id.
                $expected = $param === 'slug' ? BlogPost::SLUG_PATTERN : '[0-9]+';
                $this->assertSame($expected, $route->wheres[$param] ?? null, "{$label}: {{$param}} must be constrained");
            }
        }
    }

    public function test_every_v1_route_declares_a_scope(): void
    {
        foreach (RouteDocs::v1() as $route) {
            $label = implode('|', RouteDocs::methods($route)).' '.$route->uri();
            $middleware = RouteDocs::middleware($route);

            if (RouteDocs::scope($route) !== 'public') {
                $this->assertContains('auth.session', $middleware, "{$label}: signed-in scope");
                $this->assertContains('throttle:api', $middleware, "{$label}: shared limiter");
            }
        }
    }

    public function test_the_oauth_callback_is_permanent(): void
    {
        $callback = Route::getRoutes()->getByName('smtp.microsoft.callback');

        $this->assertInstanceOf(RouteObject::class, $callback);
        $this->assertSame('api/admin/smtp/microsoft/callback', $callback->uri());
    }

    public function test_docs_routes_is_in_sync_with_the_route_table(): void
    {
        $path = base_path('docs/ROUTES.md');
        $generated = RouteDocs::markdown();

        if (getenv('UPDATE_ROUTE_DOCS')) {
            file_put_contents($path, $generated);
        }

        $this->assertSame($generated, (string) file_get_contents($path), 'docs/ROUTES.md is stale: run UPDATE_ROUTE_DOCS=1 composer test');
    }
}
