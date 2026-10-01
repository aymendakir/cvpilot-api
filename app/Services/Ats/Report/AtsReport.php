<?php

namespace App\Services\Ats\Report;

use App\Services\Ats\Scoring\Assessment;

/**
 * One analysis, ready to serialise (SPEC-ats.md §5.2 `AtsReport`). `toArray()` is the response body;
 * the assessment stays available for tests and the S4 Resource.
 */
final readonly class AtsReport
{
    /** @param array<string, mixed> $payload the §5.2 shape */
    public function __construct(
        public Assessment $assessment,
        private array $payload,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->payload;
    }
}
