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
    private const ACTION_CHANGES = [
        'GET /' => 'ConsoleController@__invoke',
        'GET api/csrf' => 'CsrfTokenController@__invoke',
        'GET api/me' => 'CurrentUserController@__invoke',
    ];

    /** Legacy shims whose action differs from the shared v1 action: "METHOD uri" => "Controller@method". */
    private const SHIMS = [
        'POST api/admin/cache/clear' => 'CacheController@clear',
        'POST api/logout' => 'AuthController@logoutLegacy',
    ];

    /** CareerController was split: old action => new action (one-to-one, behaviour untouched). */
    private const MOVED_ACTIONS = [
        'CareerController@dashboard' => 'InsightsController@dashboard',
        'CareerController@analytics' => 'InsightsController@analytics',
        'CareerController@library' => 'LibraryController@__invoke',
        'CareerController@workspaces' => 'JobWorkspaceController@index',
        'CareerController@storeWorkspace' => 'JobWorkspaceController@store',
        'CareerController@workspace' => 'JobWorkspaceController@show',
        'CareerController@versions' => 'CvVersionController@index',
        'CareerController@storeVersion' => 'CvVersionController@store',
        'CareerController@version' => 'CvVersionController@show',
        'CareerController@updateVersion' => 'CvVersionController@update',
        'CareerController@destroyVersion' => 'CvVersionController@destroy',
        'CareerController@destroyReport' => 'ReportController@destroy',
        'CareerController@startInterview' => 'InterviewController@store',
        'CareerController@interview' => 'InterviewController@show',
        'CareerController@destroyInterview' => 'InterviewController@destroy',
        'CareerController@replyInterview' => 'InterviewController@reply',
        'CareerController@finishInterview' => 'InterviewController@finish',
        'CareerController@recruiterView' => 'CareerAiController@recruiterView',
        'CareerController@tailorCv' => 'CareerAiController@tailorCv',
        'CareerController@applicationPack' => 'CareerAiController@applicationPack',
        'CareerController@skillGap' => 'CareerAiController@skillGap',
        'CareerController@portfolio' => 'CareerAiController@portfolio',
        'CareerController@followUp' => 'CareerAiController@followUp',
        'CareerController@diagnostic' => 'CareerAiController@diagnostic',
    ];

    /** Middleware that legacy routes gain (never removed or reordered): `deprecated`, optionally with a successor path. */
    private const ADDED_MIDDLEWARE_PREFIX = 'deprecated';

    /** @return array<string, array{action: string, middleware: array<int, string>}> */
    private function currentTable(): array
    {
        $table = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $action = $route->getActionName() === 'Closure'
                    ? 'Closure'
                    : class_basename($route->getControllerClass()).'@'.(str_contains($route->getActionMethod(), '\\') ? '__invoke' : $route->getActionMethod());
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

            $expectedAction = self::SHIMS[$key] ?? self::ACTION_CHANGES[$key] ?? self::MOVED_ACTIONS[$row['action']] ?? $row['action'];
            if ($current[$key]['action'] !== $expectedAction) {
                $problems[] = "action changed: {$key} {$row['action']} -> {$current[$key]['action']} (expected {$expectedAction})";
            }

            $middleware = array_values(array_filter(
                $current[$key]['middleware'],
                fn ($m) => ! str_starts_with($m, self::ADDED_MIDDLEWARE_PREFIX),
            ));
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
