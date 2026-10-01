<?php

namespace App\Services\Ats\Scoring;

use App\Services\Ats\Checks\CheckResult;
use App\Services\Ats\Keywords\KeywordReport;

/** Check results, keyword report and their score, as one unit for the report (S4 serialises it). */
final readonly class Assessment
{
    /**
     * @param  array<string, CheckResult>  $results  the 17 document checks (after R4), keyed by id
     * @param  list<Suggestion>  $suggestions  ranked (R5)
     */
    public function __construct(
        public array $results,
        public ?KeywordReport $keywords,
        public ScoreResult $score,
        public array $suggestions = [],
    ) {}
}
