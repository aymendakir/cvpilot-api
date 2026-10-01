<?php

namespace App\Services\Ats\Parsing;

interface DocumentParser
{
    /** @throws UnreadableDocument */
    public function parse(string $path): ParsedDocument;
}
