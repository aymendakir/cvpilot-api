<?php

namespace App\Services\Ats\Scoring;

use App\Services\Ats\Checks\CheckStatus;
use App\Services\Ats\Keywords\KeywordReport;

/**
 * R5: the score gain if one item alone were fixed, other results unchanged and caps re-evaluated.
 * Never negative; null when the report has no score (R4).
 */
final class WhatIf
{
    /** @param array<string, CheckStatus> $statuses */
    public function __construct(
        private readonly array $statuses,
        private readonly ?KeywordReport $keywords,
        private readonly ScoreResult $base,
        private readonly ScoreCalculator $calculator = new ScoreCalculator,
    ) {}

    /** The gain if this document check passed. */
    public function checkPasses(string $id): ?int
    {
        if ($this->base->score === null) {
            return null;
        }
        $statuses = [$id => CheckStatus::Pass] + $this->statuses;

        return $this->gain($this->calculator->calculate($statuses, $this->keywords !== null, $this->keywords?->coverage(), $this->base->status));
    }

    /** The gain if the keyword at this index (KeywordReport::$matches) were matched. */
    public function keywordMatched(int $index): ?int
    {
        if ($this->base->score === null) {
            return null;
        }

        return $this->gain($this->calculator->calculate($this->statuses, true, $this->keywords?->coverage($index), $this->base->status));
    }

    private function gain(ScoreResult $result): int
    {
        return max(0, (int) $result->score - (int) $this->base->score);
    }
}
