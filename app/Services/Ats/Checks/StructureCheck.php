<?php

namespace App\Services\Ats\Checks;

use App\Services\Ats\Parsing\Confidence;
use App\Services\Ats\Parsing\Detection;

/**
 * Shared rule for checks that read a §4.1 structure signal: not inspected → unverified; detected with
 * low confidence → unverified (a check fails only at medium or high confidence); detected → fail;
 * not detected → pass.
 */
abstract class StructureCheck implements Check
{
    protected function judge(CheckContext $context, Detection $detection, string $failKey): CheckResult
    {
        if (! $context->document->structure->inspected || $detection->detected === null) {
            return CheckResult::unverified($this->id(), 'not_inspected', ['type' => $context->document->type]);
        }
        if ($detection->detected && $detection->confidence === Confidence::Low) {
            return CheckResult::unverified($this->id(), 'low_confidence', ['note' => $detection->note], Confidence::Low);
        }

        return $detection->detected
            ? CheckResult::fail($this->id(), $failKey, ['note' => $detection->note] + $this->scalars($detection->extra), $detection->confidence, $detection->samples)
            : CheckResult::pass($this->id(), 'ok', [], $detection->confidence);
    }

    /** @return array<string, scalar|null> */
    protected function scalars(array $extra): array
    {
        return array_filter($extra, fn ($v) => is_scalar($v) || $v === null);
    }
}
