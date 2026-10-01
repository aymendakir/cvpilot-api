<?php

namespace Tests\Feature\Ats;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The expected results in tests/fixtures/ats/expected/ are the golden values S3/S4 must reproduce
 * (SPEC-ats.md §8, §11). Until the engine exists, this test checks that they are internally
 * consistent with the scoring rules (§6 R1, R2, R3, R5, R8) and with the fixture files, so a typo in
 * the spec tables is caught now rather than when the engine disagrees with it.
 */
class ExpectedResultsTest extends TestCase
{
    private const DIR = __DIR__.'/../../fixtures/ats';

    /** §6 check table: id => [category, severity, max]. */
    private const CHECKS = [
        'readable_text' => ['format', 'blocker', 6],
        'single_column' => ['format', 'major', 6],
        'layout_tables' => ['format', 'major', 5],
        'images' => ['format', 'major', 5],
        'text_boxes_headers' => ['format', 'minor', 3],
        'file_supported' => ['format', 'minor', 3],
        'clean_characters' => ['format', 'minor', 2],
        'email' => ['sections', 'major', 5],
        'phone' => ['sections', 'minor', 3],
        'experience_section' => ['sections', 'major', 6],
        'education_section' => ['sections', 'minor', 4],
        'skills_section' => ['sections', 'minor', 4],
        'dates' => ['sections', 'minor', 3],
        'action_verbs' => ['content', 'minor', 5],
        'quantified_results' => ['content', 'minor', 5],
        'length' => ['content', 'minor', 3],
        'no_duplicates' => ['content', 'minor', 2],
        'keyword_coverage' => ['keywords', 'minor', 30],
    ];

    /** Structure checks that pasted text cannot verify (§4.1, F9). */
    private const STRUCTURE = ['single_column', 'layout_tables', 'images', 'text_boxes_headers', 'file_supported'];

    private const SEVERITY = ['blocker' => 0, 'major' => 1, 'minor' => 2, 'info' => 3];

    /** @return array<string, mixed> */
    private static function load(string $path): array
    {
        return json_decode((string) file_get_contents(self::DIR.'/'.$path), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array{0: string}> */
    public static function cases(): array
    {
        $cases = [];
        foreach (self::load('manifest.json')['cases'] as $case) {
            $cases[$case['case']] = [$case['case']];
        }

        return $cases;
    }

    private static function roundHalfUp(float $value): int
    {
        return (int) floor($value + 0.5);
    }

    /** R1: score over applicable checks only. */
    private static function raw(array $checks): int
    {
        $earned = $max = 0;
        foreach ($checks as $check) {
            if ($check['applicable']) {
                $earned += $check['earned'];
                $max += $check['max'];
            }
        }

        return self::roundHalfUp(100 * $earned / $max);
    }

    /** R3: the caps a set of check results triggers, with `applied` (binding) for a raw score. */
    private static function caps(array $checks, int $raw): array
    {
        $failed = fn (string $id) => ($checks[$id]['status'] ?? null) === 'fail';
        $caps = [];
        foreach ([
            'major_format_issue' => [84, $failed('single_column') || $failed('layout_tables') || $failed('images')],
            'no_email' => [79, $failed('email')],
            'no_experience' => [74, $failed('experience_section')],
        ] as $id => [$limit, $triggered]) {
            if ($triggered) {
                $caps[] = ['id' => $id, 'limit' => $limit, 'applied' => $limit < $raw];
            }
        }

        return $caps;
    }

    private static function score(array $checks): int
    {
        $raw = self::raw($checks);
        $limits = array_column(array_filter(self::caps($checks, $raw), fn ($c) => $c['applied']), 'limit');

        return min([$raw, ...$limits]);
    }

    /** R2: weighted coverage. */
    private static function coverage(array $items): float
    {
        $total = array_sum(array_column($items, 'weight'));
        $matched = array_sum(array_column(array_filter($items, fn ($i) => $i['status'] === 'matched'), 'weight'));

        return $matched / $total;
    }

    /** R5: the score with one item fixed, everything else unchanged and caps re-evaluated. */
    private static function whatIf(array $expected, string $suggestionId): int
    {
        $checks = $expected['checks'];
        if (str_starts_with($suggestionId, 'keyword:')) {
            $term = substr($suggestionId, strlen('keyword:'));
            $items = $expected['keywords']['items'];
            $found = false;
            foreach ($items as $i => $item) {
                if (mb_strtolower($item['term']) === $term) {
                    $items[$i]['status'] = 'matched';
                    $found = true;
                }
            }
            self::assertTrue($found, "{$suggestionId} must name a keyword item");
            $checks['keyword_coverage']['earned'] = self::roundHalfUp(30 * self::coverage($items));
        } else {
            self::assertArrayHasKey($suggestionId, $checks);
            $checks[$suggestionId]['status'] = 'pass';
            $checks[$suggestionId]['earned'] = $checks[$suggestionId]['max'];
        }

        return self::score($checks);
    }

    #[DataProvider('cases')]
    public function test_expected_file_has_the_pinned_shape(string $case): void
    {
        $e = self::load("expected/{$case}.json");

        $this->assertSame($case, $e['case']);
        $this->assertContains($e['mode'], ['document', 'job_match']);
        $this->assertContains($e['source'], ['file', 'text']);
        $this->assertContains($e['score_status'], ['scored', 'insufficient_text', 'unreadable']);
        $this->assertFileExists(self::DIR.'/'.$e['cv']);
        $this->assertSame($e['job'] !== null, $e['mode'] === 'job_match');
        if ($e['job'] !== null) {
            $this->assertFileExists(self::DIR.'/'.$e['job']);
        }

        foreach ($e['checks'] as $id => $check) {
            $this->assertArrayHasKey($id, self::CHECKS, "{$case}: unknown check {$id}");
            $this->assertSame(self::CHECKS[$id][2], $check['max'], "{$case} {$id}: max");
            $this->assertContains($check['status'], ['pass', 'fail', 'unverified']);
            $this->assertSame($check['status'] !== 'unverified', $check['applicable'], "{$case} {$id}: unverified ⇔ not applicable");
            if ($id !== 'keyword_coverage') {
                $this->assertSame($check['status'] === 'pass' ? $check['max'] : 0, $check['earned'], "{$case} {$id}: pass/fail, no partial credit");
            }
        }

        if ($e['checks_complete']) {
            $expectedIds = array_keys(self::CHECKS);
            if ($e['mode'] === 'document') {
                $expectedIds = array_values(array_diff($expectedIds, ['keyword_coverage']));
            }
            $this->assertSame($expectedIds, array_keys($e['checks']), "{$case}: every check, in §6 order");
            foreach (self::STRUCTURE as $id) {
                $this->assertSame($e['source'] === 'text', $e['checks'][$id]['status'] === 'unverified', "{$case} {$id}: unverified only for pasted text");
            }
        }
    }

    #[DataProvider('cases')]
    public function test_scores_follow_r1_r3_r4_and_r8(string $case): void
    {
        $e = self::load("expected/{$case}.json");

        if ($e['score_status'] !== 'scored') {
            $this->assertNull($e['score']);
            $this->assertNull($e['raw_score']);
            $this->assertNull($e['grade']);
            $this->assertSame('fail', $e['checks']['readable_text']['status'], 'R4: unreadable means readable_text failed');

            return;
        }

        $raw = self::raw($e['checks']);
        $this->assertSame($e['raw_score'], $raw, "{$case}: raw score");
        $this->assertSame($e['caps'], self::caps($e['checks'], $raw), "{$case}: caps");
        $this->assertSame($e['score'], self::score($e['checks']), "{$case}: score");
        $grade = $e['score'] >= 85 ? 'strong' : ($e['score'] >= 70 ? 'good' : ($e['score'] >= 50 ? 'needs_work' : 'poor'));
        $this->assertSame($grade, $e['grade'], "{$case}: grade");
    }

    #[DataProvider('cases')]
    public function test_keyword_coverage_follows_r2(string $case): void
    {
        $e = self::load("expected/{$case}.json");
        if ($e['mode'] === 'document') {
            $this->assertNull($e['keywords']);

            return;
        }

        $k = $e['keywords'];
        foreach ($k['items'] as $item) {
            $this->assertSame($item['kind'] === 'required' ? 2 : 1, $item['weight']);
            $this->assertSame($item['status'] === 'matched', $item['match_type'] !== null);
            $this->assertContains($item['match_type'], [null, 'exact', 'synonym', 'stem']);
        }
        $coverage = self::coverage($k['items']);
        $this->assertSame(round($coverage, 3), $k['coverage']);
        $this->assertSame(self::roundHalfUp(30 * $coverage), $e['checks']['keyword_coverage']['earned']);
        foreach (['required', 'preferred'] as $kind) {
            $ofKind = array_filter($k['items'], fn ($i) => $i['kind'] === $kind);
            $this->assertSame(count($ofKind), $k[$kind]['total']);
            $this->assertSame(count(array_filter($ofKind, fn ($i) => $i['status'] === 'matched')), $k[$kind]['matched']);
        }
    }

    #[DataProvider('cases')]
    public function test_pinned_suggestions_follow_r5_and_their_order(string $case): void
    {
        $e = self::load("expected/{$case}.json");
        $previous = null;
        $this->assertSame($e['score'] === 100, $e['top_suggestions'] === [], "{$case}: suggestions exist unless the score is 100");

        foreach ($e['top_suggestions'] as $suggestion) {
            if ($suggestion['impact_points'] === null) {
                $this->assertNotSame('scored', $e['score_status'], 'impact is only unpinned for unscored reports');

                continue;
            }
            $this->assertSame($suggestion['impact_points'], self::whatIf($e, $suggestion['id']) - $e['score'], "{$case} {$suggestion['id']}: what-if impact");

            $severity = str_starts_with($suggestion['id'], 'keyword:') ? 'minor' : self::CHECKS[$suggestion['id']][1];
            $key = [-$suggestion['impact_points'], self::SEVERITY[$severity], $suggestion['id']];
            if ($previous !== null) {
                $this->assertLessThan(0, $previous <=> $key, "{$case}: suggestions sorted by impact, severity, id");
            }
            $previous = $key;
        }
    }

    public function test_corrected_variants_score_exactly_current_plus_impact(): void
    {
        $checked = 0;
        foreach (self::cases() as [$case]) {
            $e = self::load("expected/{$case}.json");
            if (! isset($e['corrected'])) {
                continue;
            }
            $fixed = self::load("expected/{$e['corrected']['case']}.json");
            $impact = collect($e['top_suggestions'])->firstWhere('id', $e['corrected']['check'])['impact_points'];

            $this->assertSame($e['score'] + $impact, $fixed['score'], "{$case} → {$e['corrected']['case']} (§15 item 5)");
            $checked++;
        }

        $this->assertSame(4, $checked, 'F4, F5, F7 and F8 have corrected variants');
    }

    #[DataProvider('cases')]
    public function test_job_terms_sit_in_the_right_block_and_cv_text_agrees_with_each_match_type(string $case): void
    {
        $e = self::load("expected/{$case}.json");
        if ($e['mode'] === 'document') {
            $this->expectNotToPerformAssertions();

            return;
        }

        // One term per line under its block heading (§6.1 step 2).
        $block = null;
        $kinds = [];
        foreach (preg_split('/\R/u', (string) file_get_contents(self::DIR.'/'.$e['job'])) as $line) {
            $line = trim($line);
            if (preg_match('/^(requirements|profil recherché)\s*:$/iu', $line)) {
                $block = 'required';
            } elseif (preg_match('/^(nice to have|atout)\s*:$/iu', $line)) {
                $block = 'preferred';
            } elseif ($line === '') {
                $block = null;
            } elseif ($block !== null) {
                $kinds[mb_strtolower($line)] = $block;
            }
        }
        $terms = [];
        foreach ($e['keywords']['items'] as $item) {
            $terms[mb_strtolower($item['term'])] = $item['kind'];
        }
        $this->assertSame($kinds, $terms, "{$case}: job blocks list exactly the expected terms");

        $cv = str_ends_with($e['cv'], '.pdf') ? FixturesTest::pdfText(basename($e['cv'])) : FixturesTest::docxText(basename($e['cv']));
        foreach ($e['keywords']['items'] as $item) {
            $exact = FixturesTest::occurrences($cv, $item['term']);
            if ($item['match_type'] === 'exact') {
                $this->assertGreaterThan(0, $exact, "{$case}: {$item['term']} appears verbatim");
            } else {
                $this->assertSame(0, $exact, "{$case}: {$item['term']} must not appear verbatim ({$item['status']}, {$item['match_type']})");
            }
        }
    }
}
