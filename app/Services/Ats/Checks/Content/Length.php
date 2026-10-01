<?php

namespace App\Services\Ats\Checks\Content;

use App\Services\Ats\Checks\Check;
use App\Services\Ats\Checks\CheckContext;
use App\Services\Ats\Checks\CheckResult;

/** §6 length: 250–1 000 words. */
final class Length implements Check
{
    public const MIN_WORDS = 250;

    public const MAX_WORDS = 1000;

    public function id(): string
    {
        return 'length';
    }

    public function run(CheckContext $context): CheckResult
    {
        $words = $context->document->wordCount;
        $params = ['words' => $words, 'min' => self::MIN_WORDS, 'max' => self::MAX_WORDS];

        return match (true) {
            $words < self::MIN_WORDS => CheckResult::fail($this->id(), 'too_short', $params),
            $words > self::MAX_WORDS => CheckResult::fail($this->id(), 'too_long', $params),
            default => CheckResult::pass($this->id(), 'ok', $params),
        };
    }
}
