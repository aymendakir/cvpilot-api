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
        // Helpers that only use the request for the signed-in user, ip or user agent.
        foreach (['AuthController::audit(', '$this->report(', '$this->signOut('] as $helper) {
            $body = str_replace($helper.'$'.$var, $helper.'null', $body);
        }

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
        $this->assertSame([], $this->offenders(), 'These v1 actions read input through a plain Request: use a FormRequest');
    }

    public function test_the_guard_detects_a_plain_request_that_reads_input(): void
    {
        $plain = new class
        {
            public function reads(Request $r)
            {
                return $r->input('x');
            }

            public function harmless(Request $r)
            {
                return $r->user()->id;
            }
        };

        $this->assertTrue($this->readsInput(new ReflectionMethod($plain, 'reads'), 'r'));
        $this->assertFalse($this->readsInput(new ReflectionMethod($plain, 'harmless'), 'r'));
    }
}
