<?php

namespace Tests\Feature\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Route as RouteObject;
use ReflectionMethod;
use Tests\Support\RouteDocs;
use Tests\TestCase;

/**
 * SPEC §5.9: a /api/v1 controller that reads input must take a FormRequest, not a plain Request.
 * A method that only uses the request for `user()`, `ip()`, `userAgent()` or `session()` may keep it.
 * Routes not yet converted are pinned in tests/fixtures/unconverted-requests.json; the list only shrinks.
 */
class FormRequestGuardTest extends TestCase
{
    private const HARMLESS = ['user', 'ip', 'userAgent', 'session', 'route'];

    /** @return array<int, string> "Controller@method" of v1 actions that read input through a plain Request */
    private function offenders(): array
    {
        $found = [];
        foreach (RouteDocs::v1() as $route) {
            $action = $this->action($route);
            if (! $action) {
                continue;
            }
            [$class, $method] = $action;
            $ref = new ReflectionMethod($class, $method);
            foreach ($ref->getParameters() as $param) {
                if ($param->getType()?->getName() !== Request::class) {
                    continue;
                }
                if ($this->readsInput($ref, $param->getName())) {
                    $found[class_basename($class).'@'.$method] = true;
                }
            }
        }
        $found = array_keys($found);
        sort($found);

        return $found;
    }

    /** @return array{0: class-string, 1: string}|null */
    private function action(RouteObject $route): ?array
    {
        $class = $route->getControllerClass();
        $method = $route->getActionMethod();

        return $class ? [$class, $method === $class ? '__invoke' : $method] : null;
    }

    private function readsInput(ReflectionMethod $ref, string $var): bool
    {
        $lines = array_slice(file($ref->getFileName()), $ref->getStartLine(), $ref->getEndLine() - $ref->getStartLine());
        $body = implode('', $lines);
        $body = str_replace('AuthController::audit($'.$var.',', 'AuthController::audit(', $body);

        // Any use of the variable other than a harmless accessor counts as reading input.
        preg_match_all('/\$'.$var.'\b(->(\w+))?/', $body, $m, PREG_SET_ORDER);
        foreach ($m as $use) {
            if (! isset($use[2]) || ! in_array($use[2], self::HARMLESS, true)) {
                return true;
            }
        }

        return false;
    }

    public function test_v1_actions_that_read_input_use_form_requests(): void
    {
        $pinned = json_decode((string) file_get_contents(base_path('tests/fixtures/unconverted-requests.json')), true);
        $offenders = $this->offenders();

        if (getenv('UPDATE_REQUEST_GUARD')) {
            file_put_contents(base_path('tests/fixtures/unconverted-requests.json'), json_encode($offenders, JSON_PRETTY_PRINT)."\n");
        }

        $this->assertSame([], array_values(array_diff($offenders, $pinned)), 'New v1 action reads input through a plain Request: use a FormRequest');
        $this->assertSame([], array_values(array_diff($pinned, $offenders)), 'Converted: remove these from tests/fixtures/unconverted-requests.json');
    }
}
