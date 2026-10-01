<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Tests\Support\RouteDocs;
use Tests\TestCase;

/**
 * SPEC §7 item 9: only the two anonymous, throttled, honeypot-protected endpoints skip CSRF
 * (on their legacy and v1 paths). Tests run with CSRF checks off, so the exemption list is checked directly.
 */
class CsrfExemptionTest extends TestCase
{
    private const EXEMPT = [
        'POST api/analytics/events', 'POST api/contact', 'POST api/v1/analytics/events', 'POST api/v1/contact-messages',
    ];

    private function isExempt(string $method, string $uri): bool
    {
        $request = Request::create('/'.ltrim(preg_replace('/\{\w+\}/', '1', $uri), '/'), $method);

        foreach (app(VerifyCsrfToken::class)->getExcludedPaths() as $except) {
            if ($request->fullUrlIs($except) || $request->is($except)) {
                return true;
            }
        }

        return false;
    }

    public function test_exactly_the_two_anonymous_endpoints_are_exempt(): void
    {
        $exempt = [];
        foreach (array_merge(RouteDocs::v1(), RouteDocs::legacy()) as $route) {
            foreach (RouteDocs::methods($route) as $method) {
                if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                if ($this->isExempt($method, $route->uri())) {
                    $exempt[] = "{$method} {$route->uri()}";
                }
            }
        }
        sort($exempt);
        $expected = self::EXEMPT;
        sort($expected);

        $this->assertSame($expected, $exempt);
    }

    public function test_the_exempt_routes_exist_and_are_public(): void
    {
        foreach (self::EXEMPT as $label) {
            [$method, $uri] = explode(' ', $label);
            $route = collect(array_merge(RouteDocs::v1(), RouteDocs::legacy()))->first(fn ($r) => $r->uri() === $uri && in_array($method, RouteDocs::methods($r), true));

            $this->assertNotNull($route, "{$label} should exist");
            $this->assertSame('public', RouteDocs::scope($route), "{$label} must stay anonymous");
            $this->assertNotSame('', RouteDocs::throttle($route), "{$label} must stay throttled");
        }
    }

    public function test_state_changing_routes_with_a_session_are_never_exempt(): void
    {
        $this->assertFalse($this->isExempt('POST', 'api/v1/auth/login'));
        $this->assertFalse($this->isExempt('POST', 'api/login'));
        $this->assertFalse($this->isExempt('DELETE', 'api/v1/me'));
        $this->assertFalse($this->isExempt('PATCH', 'api/v1/admin/users/1'));
        $this->assertFalse($this->isExempt('POST', 'api/admin/smtp/test'));
    }
}
