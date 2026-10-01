<?php

namespace App\Services\Ats\Keywords;

use App\Services\Ats\Sections\Sections;

/** Job description + CV sections → keyword report (SPEC-ats.md §6.1, §6.2, R2, R6). */
final class KeywordAnalyzer
{
    private readonly KeywordMatcher $matcher;

    public function __construct(
        Taxonomy $taxonomy = new Taxonomy,
        private ?KeywordExtractor $extractor = null,
    ) {
        $this->matcher = new KeywordMatcher($taxonomy);
        $this->extractor ??= new KeywordExtractor($taxonomy);
    }

    /** @param string $language CV language (en|fr|other): stem matching only for en/fr */
    public function analyze(string $jobDescription, Sections $sections, string $language): KeywordReport
    {
        $keywords = $this->extractor->extract($jobDescription);
        $cv = new CvIndex($sections, $language);

        return new KeywordReport(
            array_map(fn (JobKeyword $k) => $this->matcher->match($k, $cv), $keywords),
            (new StuffingDetector($this->matcher))->detect($keywords, $cv),
        );
    }
}
