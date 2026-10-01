<?php

namespace Tests\Feature\Ats;

use App\Services\Ats\AtsAnalyzer;
use App\Services\Ats\Report\AtsReport;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The full report (SPEC-ats.md §5.2) produced in-process for every fixture: shape and types, golden
 * values, determinism, time budget. S4 adds the route and the JSON Schema on top.
 */
class AtsAnalyzerTest extends TestCase
{
    private const DIR = __DIR__.'/../../fixtures/ats';

    private const TOP = ['version', 'mode', 'score_status', 'score', 'raw_score', 'grade', 'summary', 'locale', 'language', 'document',
        'categories', 'keywords', 'sections', 'formatting', 'caps', 'suggestions', 'limitations', 'generated_at'];

    private function analyzer(?string $at = null): AtsAnalyzer
    {
        return new AtsAnalyzer(clock: $at === null ? null : fn () => new DateTimeImmutable($at, new DateTimeZone('UTC')));
    }

    private function report(string $cv, ?string $job, ?string $locale = null, ?AtsAnalyzer $analyzer = null, bool $includeText = false): AtsReport
    {
        $analyzer ??= $this->analyzer();
        $jd = $job === null ? null : (string) file_get_contents(self::DIR."/{$job}");

        return str_ends_with($cv, '.txt')
            ? $analyzer->analyzeText((string) file_get_contents(self::DIR."/{$cv}"), $jd, $locale, $includeText)
            : $analyzer->analyzeFile(self::DIR."/{$cv}", basename($cv), $jd, $locale, $includeText);
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

    #[DataProvider('cases')]
    public function test_every_fixture_gives_the_full_report_with_the_golden_values(string $case): void
    {
        $expected = json_decode((string) file_get_contents(self::DIR."/expected/{$case}.json"), true);
        $report = $this->report($expected['cv'], $expected['job'])->toArray();

        $this->assertSame(self::TOP, array_keys($report));
        $this->assertSame('ats-2.0', $report['version']);
        $this->assertSame($expected['mode'], $report['mode']);
        $this->assertSame([$expected['score_status'], $expected['score'], $expected['raw_score'], $expected['grade']],
            [$report['score_status'], $report['score'], $report['raw_score'], $report['grade']]);
        $this->assertSame($expected['caps'], array_map(fn ($c) => array_diff_key($c, ['reason' => 1]), $report['caps']));
        $this->assertSame(str_contains($expected['cv'], '-fr') ? 'fr' : 'en', $report['locale']);

        // categories and checks
        $this->assertSame($expected['mode'] === 'job_match' ? ['format', 'sections', 'content', 'keywords'] : ['format', 'sections', 'content'],
            array_column($report['categories'], 'id'));
        $checks = array_merge(...array_column($report['categories'], 'checks'));
        $this->assertSame(array_map(fn ($c) => [$c['status'], $c['earned']], $expected['checks']),
            array_combine(array_column($checks, 'id'), array_map(fn ($c) => [$c['status'], $c['earned']], $checks)));
        foreach ($checks as $check) {
            $this->assertSame(['id', 'title', 'severity', 'status', 'earned', 'max', 'applicable', 'confidence', 'finding', 'action', 'evidence'], array_keys($check));
            $this->assertContains($check['confidence'], ['high', 'medium', 'low']);
            $this->assertSame($check['status'] !== 'unverified', $check['applicable']);
            $this->assertSame($check['status'] === 'fail', $check['action'] !== null, "{$case} {$check['id']}: action iff failed");
            $this->assertLessThanOrEqual(3, count($check['evidence']));
        }
        if ($report['raw_score'] !== null) {
            $earned = array_sum(array_column($report['categories'], 'earned'));
            $max = array_sum(array_column($report['categories'], 'max'));
            $this->assertSame($report['raw_score'], (int) floor(100 * $earned / $max + 0.5 + 1e-9));
        }

        // suggestions
        $top = array_map(fn ($s) => ['id' => $s['id'], 'impact_points' => $s['impact_points']], array_slice($report['suggestions'], 0, count($expected['top_suggestions'])));
        $this->assertSame($expected['top_suggestions'], $top);
        foreach ($report['suggestions'] as $i => $suggestion) {
            $this->assertSame(['id', 'rank', 'severity', 'category', 'check_id', 'title', 'detail', 'action', 'impact_points', 'evidence'], array_keys($suggestion));
            $this->assertSame($i + 1, $suggestion['rank']);
        }

        // the rest of the shape
        $this->assertSame(['detected', 'supported'], array_keys($report['language']));
        $this->assertSame(['source', 'file_name', 'type', 'size_bytes', 'pages', 'word_count', 'text_extractable', 'structure_inspected'], array_keys($report['document']));
        $this->assertSame(['contact', 'experience', 'education', 'skills'], array_keys($report['sections']));
        $this->assertSame(['columns', 'tables', 'images', 'text_boxes', 'header_footer', 'glyph_issues'], array_keys($report['formatting']));
        $this->assertSame(['detected', 'confidence', 'note', 'count', 'largest_area_pct'], array_keys($report['formatting']['images']));
        $this->assertSame($expected['mode'] === 'job_match', $report['keywords'] !== null);
        $this->assertNotEmpty($report['limitations']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $report['generated_at']);

        array_walk_recursive($report, function ($value) use ($case) {
            if (is_string($value)) {
                $this->assertDoesNotMatchRegularExpression('/^ats\.|(?<![\w\/]):[a-z_]{2,}/', $value, "{$case}: raw key or placeholder");
            }
        });
    }

    public function test_same_input_gives_the_same_report_except_generated_at(): void
    {
        $a = $this->report('cvs/two-column.pdf', 'jobs/laravel-dev-short.txt')->toArray();
        $b = $this->report('cvs/two-column.pdf', 'jobs/laravel-dev-short.txt')->toArray();
        unset($a['generated_at'], $b['generated_at']);
        $this->assertSame($a, $b);

        $fixed = $this->analyzer('2026-10-01T10:00:00Z');
        $this->assertSame('2026-10-01T10:00:00Z', $this->report('cvs/clean-en.docx', null, analyzer: $fixed)->toArray()['generated_at']);
    }

    public function test_the_two_column_example_reads_like_section_5_3(): void
    {
        $report = $this->report('cvs/two-column.pdf', 'jobs/laravel-dev-short.txt')->toArray();

        $this->assertSame('Good score (84/100); the biggest gain is to use a single-column layout (+9 points).', $report['summary']);
        $this->assertSame([['id' => 'major_format_issue', 'limit' => 84, 'applied' => true,
            'reason' => 'A major layout problem (columns, tables or large images) limits the score to 84.']], $report['caps']);
        $this->assertTrue($report['formatting']['columns']['detected']);
        $this->assertSame('Text is laid out in two columns on page 1, 2.', $report['formatting']['columns']['note']);
        $this->assertSame(['email' => 'samir.benali@example.com', 'phone' => '+212 600 123 456'], $report['sections']['contact']);
        $this->assertSame(['found' => true, 'heading' => 'Work Experience'], array_slice($report['sections']['experience'], 0, 2));
        $this->assertSame(['source' => 'file', 'file_name' => 'two-column.pdf', 'type' => 'pdf'], array_slice($report['document'], 0, 3));
        $this->assertContains('Layout detection in PDFs is approximate; each finding shows how confident it is.', $report['limitations']);
        $keywordCheck = $report['categories'][3]['checks'][0];
        $this->assertSame(['keyword_coverage', 'fail', 23, 30], [$keywordCheck['id'], $keywordCheck['status'], $keywordCheck['earned'], $keywordCheck['max']]);
        $this->assertSame('7 of 10 job keywords appear in your CV (weighted coverage 75 %).', $keywordCheck['finding']);
    }

    public function test_locale_choice_and_french_report(): void
    {
        $this->assertSame('fr', AtsAnalyzer::locale(null, 'fr'));
        $this->assertSame('en', AtsAnalyzer::locale(null, 'other'));
        $this->assertSame('fr', AtsAnalyzer::locale('fr', 'en'));
        $this->assertSame('en', AtsAnalyzer::locale('de', 'other'));

        $fr = $this->report('cvs/clean-fr.docx', 'jobs/dev-symfony-fr.txt')->toArray();
        $this->assertSame('fr', $fr['locale']);
        $this->assertSame("Très bon score (98/100)\u{00A0}; le gain le plus important\u{00A0}: ajouter «\u{00A0}Vue.js\u{00A0}» si cela vous correspond (+2 points).", $fr['summary']);
        $this->assertSame('Mot-clé souhaité manquant'."\u{00A0}: Vue.js", $fr['suggestions'][0]['title']);

        $en = $this->report('cvs/clean-fr.docx', 'jobs/dev-symfony-fr.txt', 'en')->toArray();
        $this->assertSame('en', $en['locale']);
        $this->assertSame('Missing preferred keyword: Vue.js', $en['suggestions'][0]['title']);
    }

    public function test_pasted_text_unreadable_and_include_text(): void
    {
        $text = $this->report('cvs/no-email-no-exp.txt', null)->toArray();
        $this->assertSame(['source' => 'text', 'file_name' => null, 'type' => 'text'], array_slice($text['document'], 0, 3));
        $this->assertNull($text['formatting']['columns']['detected']);
        $this->assertSame('Pasted text has no layout to inspect.', $text['formatting']['columns']['note']);
        $this->assertContains('Pasted text has no layout, so the format checks were not run. Upload the file to check them.', $text['limitations']);

        $scan = $this->report('cvs/scanned.pdf', null)->toArray();
        $this->assertSame(['unreadable', null], [$scan['score_status'], $scan['score']]);
        $this->assertSame('No text could be read from this file, so it cannot be scored.', $scan['summary']);
        $this->assertContains('Scanned documents are not read (no OCR).', $scan['limitations']);

        $withText = $this->report('cvs/clean-en.docx', null, includeText: true)->toArray();
        $this->assertStringStartsWith('Samir Benali', $withText['document']['text']);
        $this->assertArrayNotHasKey('text', $this->report('cvs/clean-en.docx', null)->toArray()['document']);
    }

    public function test_time_budget_for_the_clean_cvs(): void
    {
        foreach (['cvs/clean-en.docx', 'cvs/clean-en.pdf'] as $cv) {
            $start = microtime(true);
            $this->report($cv, null);
            $this->assertLessThan(1.5, microtime(true) - $start, "{$cv} analysis ≤ 1.5 s (§8.4)");
        }
    }
}
