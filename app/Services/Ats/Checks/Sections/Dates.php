<?php

namespace App\Services\Ats\Checks\Sections;

use App\Services\Ats\Checks\Check;
use App\Services\Ats\Checks\CheckContext;
use App\Services\Ats\Checks\CheckResult;
use App\Services\Ats\Sections\DateRanges;

/**
 * §6 dates: at least 2 date ranges in the experience section, all in one style (`Mar 2022`, `03/2022`
 * or `2022`; an open end such as "Present" fits any style). Decision 2 of the S2 plan.
 */
final class Dates implements Check
{
    public function id(): string
    {
        return 'dates';
    }

    public function run(CheckContext $context): CheckResult
    {
        if (! $context->sections->found('experience')) {
            return CheckResult::fail($this->id(), 'no_experience_section');
        }
        $styles = [];
        $count = 0;
        $evidence = [];
        foreach ($context->sections->lines('experience') as $line) {
            foreach (DateRanges::find($line) as [$start, $end]) {
                $count++;
                $styles[$start] = true;
                if ($end !== null) {
                    $styles[$end] = true;
                }
                $evidence[] = $line;
            }
        }
        if ($count < 2) {
            return CheckResult::fail($this->id(), 'too_few', ['count' => $count], evidence: $evidence);
        }
        if (count($styles) > 1) {
            return CheckResult::fail($this->id(), 'mixed_styles', ['count' => $count, 'styles' => implode(', ', array_keys($styles))], evidence: $evidence);
        }

        return CheckResult::pass($this->id(), 'ok', ['count' => $count, 'style' => array_key_first($styles)], evidence: $evidence);
    }
}
