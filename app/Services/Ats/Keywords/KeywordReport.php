<?php

namespace App\Services\Ats\Keywords;

/**
 * The keyword part of the report (SPEC-ats.md §5.2 `KeywordReport`). Coverage follows R2: Σ weight of
 * matched keywords / Σ weight of all keywords (required 2, preferred 1); fewer than 3 keywords →
 * `insufficient_job_description` and no coverage. Points are S3's job.
 */
final readonly class KeywordReport
{
    public const MIN_TERMS = 3;

    /**
     * @param  list<KeywordMatch>  $matches  in job-description order
     * @param  list<array{term: string, count: int}>  $stuffing
     */
    public function __construct(
        public array $matches,
        public array $stuffing,
    ) {}

    /** @return 'ok'|'insufficient_job_description' */
    public function status(): string
    {
        return count($this->matches) < self::MIN_TERMS ? 'insufficient_job_description' : 'ok';
    }

    /**
     * Weighted coverage 0–1, 3 decimals; null when the job description is insufficient. With
     * `$assumeMatched` (an index into `matches`), that keyword counts as matched: the what-if (R5).
     */
    public function coverage(?int $assumeMatched = null): ?float
    {
        if ($this->status() !== 'ok') {
            return null;
        }
        $total = $matched = 0;
        foreach ($this->matches as $i => $match) {
            $total += $match->keyword->weight();
            $matched += $match->matched() || $i === $assumeMatched ? $match->keyword->weight() : 0;
        }

        return round($matched / $total, 3);
    }

    /** @return array{matched: int, total: int} */
    public function count(string $kind): array
    {
        $of = array_filter($this->matches, fn ($m) => $m->keyword->kind === $kind);

        return ['matched' => count(array_filter($of, fn ($m) => $m->matched())), 'total' => count($of)];
    }

    /**
     * Items sorted as §5.2 says: required missing, preferred missing, then matched (job-description
     * order within each group).
     *
     * @return list<KeywordMatch>
     */
    public function sorted(): array
    {
        $rank = fn (KeywordMatch $m) => $m->matched() ? 2 : ($m->keyword->kind === 'required' ? 0 : 1);
        $items = $this->matches;
        $order = array_flip(array_keys($items));
        uksort($items, fn ($a, $b) => [$rank($items[$a]), $order[$a]] <=> [$rank($items[$b]), $order[$b]]);

        return array_values($items);
    }

    /** The §5.2 shape. */
    public function toArray(): array
    {
        return [
            'status' => $this->status(),
            'coverage' => $this->coverage(),
            'required' => $this->count('required'),
            'preferred' => $this->count('preferred'),
            'items' => array_map(fn (KeywordMatch $m) => [
                'term' => $m->keyword->term,
                'kind' => $m->keyword->kind,
                'weight' => $m->keyword->weight(),
                'status' => $m->matched() ? 'matched' : 'missing',
                'match_type' => $m->matchType,
                'matched_as' => $m->matchedAs,
                'found_in' => $m->foundIn,
                'evidence' => $m->evidence,
                'job_context' => $m->keyword->context,
            ], $this->sorted()),
            'stuffing' => $this->stuffing,
        ];
    }
}
