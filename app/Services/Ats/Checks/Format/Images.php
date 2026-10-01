<?php

namespace App\Services\Ats\Checks\Format;

use App\Services\Ats\Checks\CheckContext;
use App\Services\Ats\Checks\CheckResult;
use App\Services\Ats\Checks\StructureCheck;
use App\Services\Ats\Parsing\Confidence;

/** §6 images: at most 2 images and none covering ≥ 15 % of the page (a small photo is fine). */
final class Images extends StructureCheck
{
    public const MAX_COUNT = 2;

    public const MAX_AREA_PCT = 15.0;

    public function id(): string
    {
        return 'images';
    }

    public function run(CheckContext $context): CheckResult
    {
        $images = $context->document->structure->images;
        if (! $context->document->structure->inspected || $images->detected === null) {
            return CheckResult::unverified($this->id(), 'not_inspected', ['type' => $context->document->type]);
        }
        $count = (int) ($images->extra['count'] ?? 0);
        $largest = $images->extra['largest_area_pct'] ?? null;
        $params = ['count' => $count, 'largest_area_pct' => $largest];
        if ($count > self::MAX_COUNT) {
            return CheckResult::fail($this->id(), 'too_many', $params, $images->confidence);
        }
        if ($largest !== null && $largest >= self::MAX_AREA_PCT) {
            return CheckResult::fail($this->id(), 'too_large', $params, $images->confidence);
        }

        return CheckResult::pass($this->id(), $count === 0 ? 'none' : 'small', $params, $images->confidence ?? Confidence::High);
    }
}
