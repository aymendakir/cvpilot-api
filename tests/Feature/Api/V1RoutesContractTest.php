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
    /** Legacy routes that have no v1 twin on purpose (SPEC §3.2, OQ 7). */
    private const LEGACY_ONLY = ['POST api/cv/{cv}/analyze', 'POST api/ai/improve-cv'];

    /** The successor uses another verb (SPEC §3.2): legacy "METHOD uri" => v1 method. */
    private const VERB_CHANGES = ['POST api/password' => 'PUT', 'POST api/admin/cache/clear' => 'DELETE'];

    /** Routes that arrive in later slices (S5): never present in the S2 table. */
    private const LATER_SLICES = ['POST v1/admin/users'];

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
        foreach (self::LATER_SLICES as $later) {
            $this->assertNotContains('public '.$later, $actual);
        }
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

    public function test_every_v1_route_declares_a_scope_and_deprecated_is_never_applied(): void
    {
        foreach (RouteDocs::v1() as $route) {
            $label = implode('|', RouteDocs::methods($route)).' '.$route->uri();
            $middleware = RouteDocs::middleware($route);

            $this->assertNull(RouteDocs::deprecation($route), "{$label}: v1 must not carry deprecation headers");
            if (RouteDocs::scope($route) !== 'public') {
                $this->assertContains('auth.session', $middleware, "{$label}: signed-in scope");
                $this->assertContains('throttle:api', $middleware, "{$label}: shared limiter");
            }
        }
    }

    public function test_every_legacy_route_has_a_v1_successor_or_is_listed_as_legacy_only(): void
    {
        $v1 = [];
        foreach (RouteDocs::v1() as $route) {
            foreach (RouteDocs::methods($route) as $method) {
                $v1["{$method} /{$route->uri()}"] = true;
            }
        }

        foreach (RouteDocs::legacy() as $route) {
            foreach (RouteDocs::methods($route) as $method) {
                $label = "{$method} {$route->uri()}";
                $successor = RouteDocs::successor($route);

                if (in_array($label, self::LEGACY_ONLY, true)) {
                    $this->assertNull($successor, "{$label}: legacy-only routes announce no successor");

                    continue;
                }
                $this->assertNotNull($successor, "{$label}: missing successor");
                $verb = self::VERB_CHANGES[$label] ?? $method;
                $this->assertTrue(isset($v1["{$verb} {$successor}"]), "{$label}: successor {$verb} {$successor} is not a v1 route");
            }
        }
    }

    public function test_the_oauth_callback_is_permanent_and_not_deprecated(): void
    {
        $callback = Route::getRoutes()->getByName('smtp.microsoft.callback');

        $this->assertInstanceOf(RouteObject::class, $callback);
        $this->assertSame('api/admin/smtp/microsoft/callback', $callback->uri());
        $this->assertNull(RouteDocs::deprecation($callback));
        $this->assertNotContains('GET api/admin/smtp/microsoft/callback', array_map(fn ($r) => 'GET '.$r->uri(), RouteDocs::legacy()));
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
