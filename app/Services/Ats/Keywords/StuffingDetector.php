<?php

namespace App\Services\Ats\Keywords;

/**
 * Job keywords repeated more than 10 times in the CV (SPEC-ats.md R6, §8.4). Reported as an `info`
 * suggestion by S3; stuffing never changes coverage or the score.
 */
final class StuffingDetector
{
    public const THRESHOLD = 10;

    public function __construct(private readonly KeywordMatcher $matcher = new KeywordMatcher) {}

    /**
     * @param  list<JobKeyword>  $keywords
     * @return list<array{term: string, count: int}>
     */
    public function detect(array $keywords, CvIndex $cv): array
    {
        $out = [];
        foreach ($keywords as $keyword) {
            $count = $this->matcher->occurrences($keyword, $cv);
            if ($count > self::THRESHOLD) {
                $out[] = ['term' => $keyword->term, 'count' => $count];
            }
        }

        return $out;
    }
}
