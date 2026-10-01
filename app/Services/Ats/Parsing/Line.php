<?php

namespace App\Services\Ats\Parsing;

/** One line of text in reading order. Position is known for PDFs only (points, origin bottom-left). */
final readonly class Line
{
    public function __construct(
        public string $text,
        public ?int $page = null,
        public ?float $x = null,
        public ?float $y = null,
    ) {}
}
