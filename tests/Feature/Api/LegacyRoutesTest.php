<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Pins the route table as it was before the /api/v1 work (tests/fixtures/routes-legacy.json).
 * Every legacy path must keep existing with the same method, controller action
 * and middleware. Only the differences listed below are allowed.
 */
class LegacyRoutesTest extends TestCase
{
    /** Closures turned into controllers: "METHOD uri" => new "Controller@method". */
    private const ACTION_CHANGES = [];

    /** Legacy shims whose action differs from the shared v1 action: "METHOD uri" => "Controller@method". */
    private const SHIMS = [];

    /** Middleware that legacy routes gain (never removed or reordered). */
    private const ADDED_MIDDLEWARE = ['deprecated'];

    /** @return array<string, array{action: string, middleware: array<int, string>}> */
    private function currentTable(): array
    {
        $table = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $action = $route->getActionName();
                $action = $action === 'Closure' ? 'Closure' : preg_replace('/^.*\\\\([A-Za-z]+@[A-Za-z]+)$/', '$1', $action);
                $table[$method.' '.$route->uri()] = [
                    'action' => $action,
                    'middleware' => array_values(array_map(fn ($m) => is_string($m) ? $m : 'Closure', $route->gatherMiddleware())),
                ];
            }
        }

        return $table;
    }

    public function test_every_legacy_route_still_exists_with_the_same_action_and_middleware(): void
    {
        $baseline = json_decode((string) file_get_contents(base_path('tests/fixtures/routes-legacy.json')), true);
        $current = $this->currentTable();
        $problems = [];

        foreach ($baseline as $row) {
            $key = $row['method'].' '.$row['uri'];

            if (! isset($current[$key])) {
                $problems[] = "missing: {$key}";

                continue;
            }

            $expectedAction = self::SHIMS[$key] ?? self::ACTION_CHANGES[$key] ?? $row['action'];
            if ($current[$key]['action'] !== $expectedAction) {
                $problems[] = "action changed: {$key} {$row['action']} -> {$current[$key]['action']} (expected {$expectedAction})";
            }

            $middleware = array_values(array_diff($current[$key]['middleware'], self::ADDED_MIDDLEWARE));
            if ($middleware !== $row['middleware']) {
                $problems[] = "middleware changed: {$key} [".implode(',', $row['middleware']).'] -> ['.implode(',', $middleware).']';
            }
        }

        $this->assertSame([], $problems);
    }

    public function test_the_baseline_has_not_been_edited_to_hide_a_removed_route(): void
    {
        $baseline = json_decode((string) file_get_contents(base_path('tests/fixtures/routes-legacy.json')), true);

        $this->assertCount(92, $baseline);
    }
}
