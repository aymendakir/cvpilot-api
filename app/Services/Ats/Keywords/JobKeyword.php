<?php

namespace App\Services\Ats\Keywords;

/** One keyword taken from the job description (SPEC-ats.md §6.1). */
final readonly class JobKeyword
{
    /**
     * @param  string  $term  as written in the job description ("REST APIs", "Gestion de projet")
     * @param  'required'|'preferred'  $kind
     * @param  ?int  $group  taxonomy group, null for a list item that is not in the taxonomy
     * @param  int  $frequency  occurrences of the term (or its group) in the job description
     * @param  ?string  $context  the job description line the term came from (≤ 200 chars)
     */
    public function __construct(
        public string $term,
        public string $kind,
        public ?int $group,
        public int $frequency,
        public ?string $context,
    ) {}

    public function weight(): int
    {
        return $this->kind === 'required' ? 2 : 1;
    }
}
