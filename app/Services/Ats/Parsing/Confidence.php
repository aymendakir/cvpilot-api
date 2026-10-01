<?php

namespace App\Services\Ats\Parsing;

/** How sure a structure finding is (SPEC-ats.md §4.1). A check fails only at medium or high. */
enum Confidence: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function isLow(): bool
    {
        return $this === self::Low;
    }
}
