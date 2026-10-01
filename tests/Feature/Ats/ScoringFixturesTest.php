<?php

namespace Tests\Feature\Ats;

use App\Services\Ats\Checks\CheckRunner;
use App\Services\Ats\Keywords\KeywordAnalyzer;
use App\Services\Ats\Parsing\DocumentReader;
use App\Services\Ats\Scoring\Assessment;
use App\Services\Ats\Scoring\Scorer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The golden scores (SPEC-ats.md §8.1–§8.2, expected/*.json) reproduced by the real pipeline: parse,
 * checks, keywords, score. Per-check status and earned points, raw score, caps, score and grade.
 */
class ScoringFixturesTest extends TestCase
{
    private const DIR = __DIR__.'/../../fixtures/ats';

    public static function assess(string $cv, ?string $job): Assessment
    {
        $runner = new CheckRunner;
        $document = (new DocumentReader)->parseFile(self::DIR."/{$cv}", basename($cv));
        $context = $runner->context($document);
        $keywords = $job === null ? null
            : (new KeywordAnalyzer)->analyze((string) file_get_contents(self::DIR."/{$job}"), $context->sections, $context->language);

        return (new Scorer)->assess($runner->run($document, $context), $keywords);
    }

    /** @return array<string, array{0: string}> */
    public static function cases(): array
    {
        $cases = [];
        foreach (json_decode((string) file_get_contents(self::DIR.'/manifest.json'), true)['cases'] as $case) {
            $cases[$case['case']] = [$case['case']];
        }

        return $cases;
    }

    /** @return array<string, mixed> */
    private function golden(string $case): array
    {
        return json_decode((string) file_get_contents(self::DIR."/expected/{$case}.json"), true);
    }

    #[DataProvider('cases')]
    public function test_every_expected_score_is_reproduced(string $case): void
    {
        $expected = $this->golden($case);
        $score = self::assess($expected['cv'], $expected['job'])->score;

        $checks = array_map(fn ($c) => ['status' => $c['status']->value, 'earned' => $c['earned']], $score->checks);
        $want = array_map(fn ($c) => ['status' => $c['status'], 'earned' => $c['earned']], $expected['checks']);
        $this->assertSame($want, $checks, "{$case}: per-check status and earned points");
        $this->assertSame($expected['score_status'], $score->status);
        $this->assertSame($expected['raw_score'], $score->rawScore);
        $this->assertSame($expected['score'], $score->score);
        $this->assertSame($expected['grade'], $score->grade);
        $this->assertSame($expected['caps'], $score->caps);
    }

    #[DataProvider('cases')]
    public function test_category_totals_reproduce_the_raw_score(string $case): void
    {
        $expected = $this->golden($case);
        $score = self::assess($expected['cv'], $expected['job'])->score;
        if ($score->rawScore === null) {
            $this->assertNull($score->score);

            return;
        }
        $earned = array_sum(array_column($score->categories, 'earned'));
        $max = array_sum(array_column($score->categories, 'max'));
        $this->assertSame($score->rawScore, (int) floor(100 * $earned / $max + 0.5 + 1e-9));
        foreach ($score->categories as $category) {
            $in = array_filter($score->checks, fn ($c, $id) => $c['applicable'] && config("ats.checks.{$id}.category") === $category['id'], ARRAY_FILTER_USE_BOTH);
            $this->assertSame($category['max'], array_sum(array_column($in, 'max')));
        }
    }
}
