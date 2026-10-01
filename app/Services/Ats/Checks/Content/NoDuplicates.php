<?php

namespace App\Services\Ats\Checks\Content;

use App\Services\Ats\Checks\Check;
use App\Services\Ats\Checks\CheckContext;
use App\Services\Ats\Checks\CheckResult;
use App\Services\Ats\Language\Normalizer;

/** §6 no_duplicates: no two experience bullets are the same after normalisation (case, accents, punctuation). */
final class NoDuplicates implements Check
{
    public function __construct(private readonly Normalizer $normalizer = new Normalizer) {}

    public function id(): string
    {
        return 'no_duplicates';
    }

    public function run(CheckContext $context): CheckResult
    {
        $seen = [];
        $duplicates = [];
        foreach ($context->sections->bullets() as $bullet) {
            $key = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $this->normalizer->fold($bullet)));
            if ($key === '') {
                continue;
            }
            if (isset($seen[$key])) {
                $duplicates[] = $bullet;
            }
            $seen[$key] = true;
        }

        return $duplicates === []
            ? CheckResult::pass($this->id(), 'ok')
            : CheckResult::fail($this->id(), 'duplicates', ['count' => count($duplicates)], evidence: $duplicates);
    }
}
