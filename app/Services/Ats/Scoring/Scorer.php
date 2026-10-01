<?php

namespace App\Services\Ats\Scoring;

use App\Services\Ats\Checks\CheckResult;
use App\Services\Ats\Checks\CheckStatus;
use App\Services\Ats\Keywords\KeywordReport;

/**
 * Check results + keyword report → score and ranked suggestions (SPEC-ats.md §6, R1–R6).
 *
 * Status (R4, S3 decision 23): no extractable text or garbled text → `unreadable`, and every check
 * other than readable_text becomes unverified; readable text under 40 words → `insufficient_text`, the
 * checks stay as they are. Both have no score.
 */
final class Scorer
{
    public function __construct(
        private readonly ScoreCalculator $calculator = new ScoreCalculator,
        private readonly SuggestionBuilder $suggestions = new SuggestionBuilder,
    ) {}

    /** @param array<string, CheckResult> $results */
    public function assess(array $results, ?KeywordReport $keywords = null): Assessment
    {
        $status = self::status($results['readable_text']);
        if ($status === 'unreadable') {
            foreach ($results as $id => $result) {
                if ($id !== 'readable_text' && $result->status !== CheckStatus::Unverified) {
                    $results[$id] = CheckResult::unverified($id, 'no_text');
                }
            }
        }
        $score = $this->calculator->calculate(self::statuses($results), $keywords !== null, $keywords?->coverage(), $status);

        return new Assessment($results, $keywords, $score, $this->suggestions->build($results, $keywords, $score));
    }

    /** @return 'scored'|'insufficient_text'|'unreadable' */
    public static function status(CheckResult $readableText): string
    {
        return match (true) {
            $readableText->status !== CheckStatus::Fail => 'scored',
            $readableText->findingKey === 'too_short' => 'insufficient_text',
            default => 'unreadable',
        };
    }

    /**
     * @param  array<string, CheckResult>  $results
     * @return array<string, CheckStatus>
     */
    public static function statuses(array $results): array
    {
        return array_map(fn (CheckResult $r) => $r->status, $results);
    }
}
