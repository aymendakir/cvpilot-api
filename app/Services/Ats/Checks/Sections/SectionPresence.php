<?php

namespace App\Services\Ats\Checks\Sections;

use App\Services\Ats\Checks\Check;
use App\Services\Ats\Checks\CheckContext;
use App\Services\Ats\Checks\CheckResult;

/** §6 *_section: a recognised heading (EN/FR) followed by at least one entry line. */
abstract class SectionPresence implements Check
{
    abstract protected function kind(): string;

    public function id(): string
    {
        return $this->kind().'_section';
    }

    public function run(CheckContext $context): CheckResult
    {
        $s = $context->sections;
        if (! $s->found($this->kind())) {
            return CheckResult::fail($this->id(), 'missing');
        }
        $heading = $s->headings[$this->kind()];
        $lines = $s->lines($this->kind());

        return $lines === []
            ? CheckResult::fail($this->id(), 'empty', ['heading' => $heading['text'], 'line' => $heading['line']])
            : CheckResult::pass($this->id(), 'found', ['heading' => $heading['text'], 'line' => $heading['line']], evidence: [$heading['text']]);
    }
}
