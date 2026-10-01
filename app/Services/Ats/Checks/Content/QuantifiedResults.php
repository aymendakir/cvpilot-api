<?php

namespace App\Services\Ats\Checks\Content;

use App\Services\Ats\Checks\Check;
use App\Services\Ats\Checks\CheckContext;
use App\Services\Ats\Checks\CheckResult;
use App\Services\Ats\Checks\Patterns;

/** §6 quantified_results: at least 2 experience bullets with a number, % or currency amount (years do not count). */
final class QuantifiedResults implements Check
{
    public const MIN_BULLETS = 2;

    public function id(): string
    {
        return 'quantified_results';
    }

    public function run(CheckContext $context): CheckResult
    {
        $bullets = $context->sections->bullets();
        $quantified = array_values(array_filter($bullets, fn (string $b) => Patterns::quantified($b)));
        $params = ['count' => count($quantified), 'total' => count($bullets)];

        return count($quantified) >= self::MIN_BULLETS
            ? CheckResult::pass($this->id(), 'ok', $params, evidence: $quantified)
            : CheckResult::fail($this->id(), $bullets === [] ? 'no_bullets' : 'too_few', $params, evidence: $quantified);
    }
}
