<?php

namespace App\Services\Ats\Keywords;

/** How one job keyword was found in the CV (SPEC-ats.md §5.2 `KeywordItem`, §6.2). */
final readonly class KeywordMatch
{
    /**
     * @param  'exact'|'synonym'|'stem'|null  $matchType  null when the keyword is missing
     * @param  ?string  $matchedAs  the CV wording that matched ("RESTful API")
     * @param  list<'experience'|'skills'|'education'|'other'>  $foundIn
     * @param  list<string>  $evidence  ≤ 2 CV lines, ≤ 200 chars each
     */
    public function __construct(
        public JobKeyword $keyword,
        public ?string $matchType,
        public ?string $matchedAs = null,
        public array $foundIn = [],
        public array $evidence = [],
    ) {}

    public function matched(): bool
    {
        return $this->matchType !== null;
    }

    /** Found in the skills section only: S3 suggests using it in an experience bullet (R6 `keyword_skills_only`). */
    public function skillsOnly(): bool
    {
        return $this->foundIn === ['skills'];
    }
}
