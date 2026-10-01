<?php

namespace App\Services\Ats\Scoring;

use App\Services\Ats\Checks\CheckResult;
use App\Services\Ats\Checks\CheckStatus;
use App\Services\Ats\Keywords\KeywordMatch;
use App\Services\Ats\Keywords\KeywordReport;

/**
 * Suggestions and their ranking (SPEC-ats.md R5, R6; S3 decisions 24–26):
 *
 * - one per failed document check (unverified checks get none);
 * - missing required keywords (minor, up to 5) and missing preferred keywords (info, up to 3), each
 *   chosen by impact then id;
 * - terms found only in the skills section (info, up to 3, required first, then id);
 * - stuffed terms (info, impact 0: stuffing never changes the score);
 * - an insufficient job description gives one info suggestion instead of keyword suggestions.
 *
 * Ranked by impact desc, then severity (blocker > major > minor > info), then id; ranks 1..n.
 * Ids: "<check_id>", "keyword:<term>", "keyword_skills_only:<term>", "keyword_stuffing:<term>"
 * (term lower-cased, spaces kept).
 */
final class SuggestionBuilder
{
    private const SEVERITY_ORDER = ['blocker' => 0, 'major' => 1, 'minor' => 2, 'info' => 3];

    /**
     * @param  array<string, CheckResult>  $results
     * @return list<Suggestion>
     */
    public function build(array $results, ?KeywordReport $keywords, ScoreResult $score): array
    {
        $whatIf = new WhatIf(Scorer::statuses($results), $keywords, $score);
        $zero = $score->score === null ? null : 0;
        $suggestions = [];

        foreach ($results as $id => $result) {
            if ($result->status === CheckStatus::Fail) {
                $check = config("ats.checks.{$id}");
                $suggestions[] = new Suggestion($id, $check['severity'], $check['category'], $id, $whatIf->checkPasses($id),
                    $result->findingKey, $result->params, $result->evidence);
            }
        }

        if ($keywords !== null) {
            if ($keywords->status() !== 'ok') {
                $suggestions[] = new Suggestion('keyword_coverage', 'info', 'keywords', 'keyword_coverage', $zero,
                    'insufficient_job_description', ['count' => count($keywords->matches)]);
            } else {
                array_push($suggestions, ...$this->missing($keywords, 'required', $whatIf), ...$this->missing($keywords, 'preferred', $whatIf));
                array_push($suggestions, ...$this->skillsOnly($keywords, $zero));
            }
            foreach ($keywords->stuffing as $stuffed) {
                $suggestions[] = new Suggestion('keyword_stuffing:'.self::termId($stuffed['term']), 'info', 'keywords', 'keyword_stuffing', $zero,
                    'stuffing', ['term' => $stuffed['term'], 'count' => $stuffed['count']]);
            }
        }

        return $this->rank($suggestions);
    }

    /** @return list<Suggestion> */
    private function missing(KeywordReport $keywords, string $kind, WhatIf $whatIf): array
    {
        $out = [];
        foreach ($keywords->matches as $i => $match) {
            if (! $match->matched() && $match->keyword->kind === $kind) {
                $out[] = new Suggestion('keyword:'.self::termId($match->keyword->term), $kind === 'required' ? 'minor' : 'info', 'keywords',
                    'keyword_missing', $whatIf->keywordMatched($i), $kind, ['term' => $match->keyword->term, 'context' => $match->keyword->context]);
            }
        }
        usort($out, fn (Suggestion $a, Suggestion $b) => [$b->impactPoints, $a->id] <=> [$a->impactPoints, $b->id]);

        return array_slice($out, 0, config("ats.suggestions.keyword_missing_{$kind}"));
    }

    /** @return list<Suggestion> */
    private function skillsOnly(KeywordReport $keywords, ?int $zero): array
    {
        $only = array_values(array_filter($keywords->matches, fn (KeywordMatch $m) => $m->matched() && $m->skillsOnly()));
        usort($only, fn (KeywordMatch $a, KeywordMatch $b) => [$a->keyword->weight() === 2 ? 0 : 1, self::termId($a->keyword->term)]
            <=> [$b->keyword->weight() === 2 ? 0 : 1, self::termId($b->keyword->term)]);

        return array_map(fn (KeywordMatch $m) => new Suggestion('keyword_skills_only:'.self::termId($m->keyword->term), 'info', 'keywords',
            'keyword_skills_only', $zero, 'skills_only', ['term' => $m->keyword->term], $m->evidence),
            array_slice($only, 0, config('ats.suggestions.keyword_skills_only')));
    }

    /**
     * @param  list<Suggestion>  $suggestions
     * @return list<Suggestion>
     */
    private function rank(array $suggestions): array
    {
        usort($suggestions, fn (Suggestion $a, Suggestion $b) => [$b->impactPoints ?? -1, self::SEVERITY_ORDER[$a->severity], $a->id]
            <=> [$a->impactPoints ?? -1, self::SEVERITY_ORDER[$b->severity], $b->id]);

        return array_map(fn (Suggestion $s, int $i) => $s->withRank($i + 1), $suggestions, array_keys($suggestions));
    }

    /** §5.2: the term lower-cased, spaces kept ("keyword:content strategy"). */
    public static function termId(string $term): string
    {
        return mb_strtolower($term, 'UTF-8');
    }
}
