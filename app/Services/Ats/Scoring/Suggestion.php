<?php

namespace App\Services\Ats\Scoring;

/**
 * One ranked suggestion (SPEC-ats.md §5.2 `Suggestion`) before its text is resolved: the message
 * catalog turns `checkId`, `key` and `params` into title, detail and action in the report locale.
 */
final readonly class Suggestion
{
    /**
     * @param  'blocker'|'major'|'minor'|'info'  $severity
     * @param  'format'|'sections'|'content'|'keywords'  $category
     * @param  ?int  $impactPoints  null only when the report has no score (R4)
     * @param  string  $key  the check's finding key, or the keyword kind (required|preferred)
     * @param  array<string, scalar|null>  $params
     * @param  list<string>  $evidence
     */
    public function __construct(
        public string $id,
        public string $severity,
        public string $category,
        public string $checkId,
        public ?int $impactPoints,
        public string $key,
        public array $params = [],
        public array $evidence = [],
        public int $rank = 0,
    ) {}

    public function withRank(int $rank): self
    {
        return new self($this->id, $this->severity, $this->category, $this->checkId, $this->impactPoints, $this->key, $this->params, $this->evidence, $rank);
    }
}
