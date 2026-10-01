<?php

namespace Tests\Feature\Ats;

use App\Services\Ats\Checks\CheckRunner;
use App\Services\Ats\Keywords\KeywordAnalyzer;
use App\Services\Ats\Keywords\KeywordReport;
use App\Services\Ats\Parsing\DocumentReader;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The golden keyword results (SPEC-ats.md §8.2, expected/F3|F4b|F11|F13.json) reproduced by the real
 * pipeline: parse the CV, detect sections and language, extract the job keywords, match them.
 */
class KeywordsFixturesTest extends TestCase
{
    private const DIR = __DIR__.'/../../fixtures/ats';

    private function analyze(string $cv, string $job): KeywordReport
    {
        $context = (new CheckRunner)->context((new DocumentReader)->parseFile(self::DIR."/{$cv}", basename($cv)));

        return (new KeywordAnalyzer)->analyze((string) file_get_contents(self::DIR."/{$job}"), $context->sections, $context->language);
    }

    /** @return array<string, array{0: string}> */
    public static function cases(): array
    {
        $cases = [];
        foreach (json_decode((string) file_get_contents(self::DIR.'/manifest.json'), true)['cases'] as $case) {
            if ($case['job'] !== null) {
                $cases[$case['case']] = [$case['case']];
            }
        }

        return $cases;
    }

    #[DataProvider('cases')]
    public function test_every_expected_keyword_result_is_reproduced(string $case): void
    {
        $golden = json_decode((string) file_get_contents(self::DIR."/expected/{$case}.json"), true);
        $expected = $golden['keywords'];
        $report = $this->analyze($golden['cv'], $golden['job'])->toArray();

        $this->assertSame($expected['status'], $report['status']);
        $this->assertEqualsWithDelta($expected['coverage'], $report['coverage'], 0.0005);
        $this->assertSame($expected['required'], $report['required']);
        $this->assertSame($expected['preferred'], $report['preferred']);

        $fields = fn (array $item) => array_intersect_key($item, array_flip(['kind', 'weight', 'status', 'match_type']));
        $want = array_combine(array_column($expected['items'], 'term'), array_map($fields, $expected['items']));
        $got = array_combine(array_column($report['items'], 'term'), array_map($fields, $report['items']));
        ksort($want);
        ksort($got);
        $this->assertSame($want, $got);

        foreach ($report['items'] as $item) {
            $this->assertSame($item['status'] === 'matched', $item['evidence'] !== [], "{$case} {$item['term']}: evidence iff matched");
            $this->assertLessThanOrEqual(2, count($item['evidence']));
            $this->assertNotNull($item['job_context']);
        }
        $this->assertSame([], $report['stuffing']);
    }

    public function test_stuffing_is_reported_and_does_not_change_coverage(): void
    {
        $clean = $this->analyze('cvs/clean-en.docx', 'jobs/laravel-dev.txt');
        $stuffed = $this->analyze('cvs/stuffing.docx', 'jobs/laravel-dev.txt');

        $this->assertSame([['term' => 'Laravel', 'count' => 15]], $stuffed->stuffing);
        $this->assertSame($clean->coverage(), $stuffed->coverage());
        $this->assertSame([], $clean->stuffing);
    }

    public function test_keyword_report_shape_follows_the_contract(): void
    {
        $report = $this->analyze('cvs/two-column.pdf', 'jobs/laravel-dev-short.txt')->toArray();

        $this->assertSame(['status', 'coverage', 'required', 'preferred', 'items', 'stuffing'], array_keys($report));
        $this->assertSame(['term', 'kind', 'weight', 'status', 'match_type', 'matched_as', 'found_in', 'evidence', 'job_context'], array_keys($report['items'][0]));
        // §5.2 order: required missing, preferred missing, matched
        $this->assertSame(['Redis', 'Kubernetes', 'Terraform'], array_column(array_slice($report['items'], 0, 3), 'term'));
        $ci = $report['items'][array_search('CI/CD', array_column($report['items'], 'term'), true)];
        $this->assertSame(['synonym', 'continuous integration', ['experience']], [$ci['match_type'], $ci['matched_as'], $ci['found_in']]);
    }
}
