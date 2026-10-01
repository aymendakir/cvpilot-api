<?php

namespace App\Services\Ats\Scoring;

use App\Services\Ats\Checks\CheckStatus;

/**
 * Points, categories, raw score, caps and grade (SPEC-ats.md §6, R1, R3, R4, R8). Pure: the same
 * statuses and coverage always give the same result, which is what the what-if recompute (R5) relies on.
 */
final class ScoreCalculator
{
    public const CATEGORIES = ['format', 'sections', 'content', 'keywords'];

    /**
     * @param  array<string, CheckStatus>  $statuses  the 17 document checks, keyed by id
     * @param  bool  $jobMatch  a job description was given (adds keyword_coverage)
     * @param  ?float  $coverage  0–1, null when the job description is insufficient (keyword_coverage unverified)
     * @param  'scored'|'insufficient_text'|'unreadable'  $status
     */
    public function calculate(array $statuses, bool $jobMatch, ?float $coverage, string $status = 'scored'): ScoreResult
    {
        $config = config('ats.checks');
        if ($jobMatch) {
            $statuses['keyword_coverage'] = match (true) {
                $coverage === null => CheckStatus::Unverified,
                $coverage >= 1.0 => CheckStatus::Pass,
                default => CheckStatus::Fail,
            };
        }

        $checks = [];
        $totals = [];
        foreach ($config as $id => $check) {
            if (! isset($statuses[$id])) {
                continue;
            }
            $points = $check['points'];
            $earned = match (true) {
                $statuses[$id] === CheckStatus::Pass => $points,
                $id === 'keyword_coverage' && $statuses[$id] === CheckStatus::Fail => self::roundHalfUp($points * $coverage),
                default => 0,
            };
            $applicable = $statuses[$id] !== CheckStatus::Unverified;
            $checks[$id] = ['status' => $statuses[$id], 'earned' => $earned, 'max' => $points, 'applicable' => $applicable];
            $totals[$check['category']] ??= ['earned' => 0, 'max' => 0];
            if ($applicable) {
                $totals[$check['category']]['earned'] += $earned;
                $totals[$check['category']]['max'] += $points;
            }
        }
        $categories = [];
        foreach (self::CATEGORIES as $id) {
            if (isset($totals[$id])) {
                $categories[] = ['id' => $id] + $totals[$id];
            }
        }

        if ($status !== 'scored') {
            return new ScoreResult($status, $checks, $categories, null, null, null, []);
        }
        $earned = array_sum(array_column($categories, 'earned'));
        $max = array_sum(array_column($categories, 'max'));
        $raw = $max > 0 ? self::roundHalfUp(100 * $earned / $max) : 0;

        $caps = [];
        $score = $raw;
        foreach (config('ats.caps') as $id => $cap) {
            $triggered = array_filter($cap['checks'], fn ($c) => ($statuses[$c] ?? null) === CheckStatus::Fail) !== [];
            if ($triggered) {
                $caps[] = ['id' => $id, 'limit' => $cap['limit'], 'applied' => $cap['limit'] < $raw];
                $score = min($score, $cap['limit']);
            }
        }

        return new ScoreResult($status, $checks, $categories, $raw, $score, self::grade($score), $caps);
    }

    public static function grade(int $score): string
    {
        foreach (config('ats.grades') as $grade => $min) {
            if ($score >= $min) {
                return $grade;
            }
        }

        return 'poor';
    }

    /** R1 rounding: halves go up (22.5 → 23). A tiny epsilon absorbs float noise (0.7083 × 30 etc.). */
    public static function roundHalfUp(float $value): int
    {
        return (int) floor($value + 0.5 + 1e-9);
    }
}
