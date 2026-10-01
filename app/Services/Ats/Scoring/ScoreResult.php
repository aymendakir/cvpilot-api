<?php

namespace App\Services\Ats\Scoring;

use App\Services\Ats\Checks\CheckStatus;

/** The numbers of one scoring run (SPEC-ats.md §5.2 score fields, `Category`, `Check.earned/max/applicable`, `caps`). */
final readonly class ScoreResult
{
    /**
     * @param  'scored'|'insufficient_text'|'unreadable'  $status
     * @param  array<string, array{status: CheckStatus, earned: int, max: int, applicable: bool}>  $checks  in §6 order
     * @param  list<array{id: string, earned: int, max: int}>  $categories  format, sections, content, keywords?
     * @param  list<array{id: string, limit: int, applied: bool}>  $caps  triggered caps only (R3)
     */
    public function __construct(
        public string $status,
        public array $checks,
        public array $categories,
        public ?int $rawScore,
        public ?int $score,
        public ?string $grade,
        public array $caps,
    ) {}
}
