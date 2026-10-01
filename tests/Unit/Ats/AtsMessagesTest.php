<?php

namespace Tests\Unit\Ats;

use App\Services\Ats\Checks\CheckContext;
use App\Services\Ats\Checks\CheckResult;
use App\Services\Ats\Checks\Format\FileSupported;
use App\Services\Ats\Parsing\Detection;
use App\Services\Ats\Parsing\ParsedDocument;
use App\Services\Ats\Parsing\Structure;
use App\Services\Ats\Scoring\MessageCatalog;
use App\Services\Ats\Sections\SectionDetector;
use Tests\Feature\Ats\ScoringFixturesTest;
use Tests\TestCase;

/** The EN/FR message catalog (lang/{en,fr}/ats.php) is complete and consistent (SPEC-ats.md §5.2, §17 item 11). */
class AtsMessagesTest extends TestCase
{
    /** Every finding key each check can return (S2), and whether it is a failure that needs an action. */
    private const KEYS = [
        'readable_text' => ['ok' => false, 'no_text' => true, 'garbled' => true, 'too_short' => true],
        'single_column' => ['ok' => false, 'columns' => true, 'not_inspected' => false, 'low_confidence' => false],
        'layout_tables' => ['ok' => false, 'tables' => true, 'not_inspected' => false, 'low_confidence' => false],
        'images' => ['none' => false, 'small' => false, 'too_many' => true, 'too_large' => true, 'not_inspected' => false],
        'text_boxes_headers' => ['ok' => false, 'text_boxes' => true, 'contact_in_header' => true, 'not_inspected' => false, 'low_confidence' => false],
        'file_supported' => ['ok' => false, 'too_large' => true, 'not_inspected' => false],
        'clean_characters' => ['ok' => false, 'glyphs' => true, 'not_inspected' => false, 'low_confidence' => false],
        'email' => ['found' => false, 'missing' => true],
        'phone' => ['found' => false, 'missing' => true],
        'experience_section' => ['found' => false, 'missing' => true, 'empty' => true],
        'education_section' => ['found' => false, 'missing' => true, 'empty' => true],
        'skills_section' => ['found' => false, 'missing' => true, 'empty' => true],
        'dates' => ['ok' => false, 'no_experience_section' => true, 'too_few' => true, 'mixed_styles' => true],
        'action_verbs' => ['ok' => false, 'weak_start' => true, 'no_bullets' => true],
        'quantified_results' => ['ok' => false, 'too_few' => true, 'no_bullets' => true],
        'length' => ['ok' => false, 'too_short' => true, 'too_long' => true],
        'no_duplicates' => ['ok' => false, 'duplicates' => true],
        'keyword_coverage' => ['complete' => false, 'partial' => true, 'insufficient_job_description' => false],
    ];

    /** @return array<string, string> dotted key => text */
    private function flat(string $locale): array
    {
        $out = [];
        $walk = function (array $node, string $prefix) use (&$walk, &$out) {
            foreach ($node as $key => $value) {
                is_array($value) ? $walk($value, "{$prefix}{$key}.") : $out["{$prefix}{$key}"] = $value;
            }
        };
        $walk(require lang_path("{$locale}/ats.php"), '');
        ksort($out);

        return $out;
    }

    /** @return list<string> */
    private function placeholders(string $text): array
    {
        preg_match_all('/:([a-z_]+)/', $text, $m);
        $names = array_values(array_unique($m[1]));
        sort($names);

        return $names;
    }

    public function test_english_and_french_have_the_same_keys_and_placeholders(): void
    {
        $en = $this->flat('en');
        $fr = $this->flat('fr');
        $this->assertSame(array_keys($en), array_keys($fr));
        foreach ($en as $key => $text) {
            $this->assertSame($this->placeholders($text), $this->placeholders($fr[$key]), "placeholders of {$key}");
            $this->assertSame(substr_count($text, '|'), substr_count($fr[$key], '|'), "plural forms of {$key}");
            $this->assertNotSame('', trim($fr[$key]), $key);
        }
    }

    public function test_every_check_and_finding_has_its_text(): void
    {
        foreach (MessageCatalog::LOCALES as $locale) {
            $catalog = new MessageCatalog($locale);
            foreach (self::KEYS as $check => $keys) {
                foreach (['title', 'suggestion', 'why', 'fix'] as $part) {
                    $this->assertTrue($catalog->has("checks.{$check}.{$part}"), "{$locale} {$check}.{$part}");
                }
                foreach ($keys as $key => $needsAction) {
                    $this->assertTrue($catalog->has("checks.{$check}.findings.{$key}") || $catalog->has("unverified.{$key}"), "{$locale} {$check} finding {$key}");
                    $this->assertSame($needsAction, $catalog->has("checks.{$check}.actions.{$key}"), "{$locale} {$check} action {$key}");
                }
            }
            foreach (array_keys(config('ats.caps')) as $cap) {
                $this->assertTrue($catalog->has("caps.{$cap}"), "{$locale} cap {$cap}");
            }
            foreach (array_keys(config('ats.grades')) as $grade) {
                $this->assertTrue($catalog->has("summary.verdicts.{$grade}"), "{$locale} verdict {$grade}");
            }
        }
    }

    public function test_every_fixture_resolves_to_complete_text_in_both_locales(): void
    {
        $manifest = json_decode((string) file_get_contents(base_path('tests/fixtures/ats/manifest.json')), true);
        $runs = array_map(fn ($c) => [$c['cv'], $c['job']], $manifest['cases']);
        $runs[] = ['cvs/stuffing.docx', 'jobs/laravel-dev.txt'];
        foreach ($runs as [$cv, $job]) {
            $assessment = ScoringFixturesTest::assess($cv, $job);
            foreach (MessageCatalog::LOCALES as $locale) {
                $catalog = new MessageCatalog($locale);
                $texts = [$catalog->summary($assessment->score, $assessment->suggestions, 30)];
                foreach ($assessment->results as $id => $result) {
                    $texts[] = $catalog->checkTitle($id);
                    $texts[] = $catalog->finding($id, $result->findingKey, $result->params);
                    $texts[] = (string) $catalog->action($id, $result->findingKey, $result->params);
                }
                foreach ($assessment->suggestions as $suggestion) {
                    array_push($texts, ...array_values($catalog->suggestion($suggestion)));
                }
                foreach ($assessment->score->caps as $cap) {
                    $texts[] = $catalog->capReason($cap['id']);
                }
                foreach ($texts as $text) {
                    $this->assertDoesNotMatchRegularExpression('/(?<![\w\/]):[a-z_]{2,}|\bats\.|\{\d|\[\d/', $text, "{$cv} {$locale}: {$text}");
                }
            }
        }
    }

    public function test_summary_and_parameters_are_localized(): void
    {
        $f4b = ScoringFixturesTest::assess('cvs/two-column.pdf', 'jobs/laravel-dev-short.txt');
        $this->assertSame('Good score (84/100); the biggest gain is to use a single-column layout (+9 points).', (new MessageCatalog('en'))->summary($f4b->score, $f4b->suggestions));
        $this->assertSame("Bon score (84/100)\u{00A0}; le gain le plus important\u{00A0}: passer à une mise en page sur une colonne (+9 points).", (new MessageCatalog('fr'))->summary($f4b->score, $f4b->suggestions));

        $fr = new MessageCatalog('fr');
        $this->assertSame("3,5\u{00A0}% des caractères sont illisibles.", $fr->finding('readable_text', 'garbled', ['percent' => 3.5]));
        $this->assertSame('Vos périodes utilisent des formats différents (mars 2022, 03/2022).', $fr->finding('dates', 'mixed_styles', ['count' => 3, 'styles' => 'month, numeric']));
        $this->assertSame("Fichier PDF, 0,10\u{00A0}Mo.", $fr->finding('file_supported', 'ok', ['type' => 'pdf', 'megabytes' => 0.1]));
        $this->assertSame('Une seule période trouvée dans votre expérience.', $fr->finding('dates', 'too_few', ['count' => 1]));
        $this->assertSame('Not checked: pasted text has no layout. Upload the file to check it.', (new MessageCatalog('en'))->finding('images', 'not_inspected', ['type' => 'text']));
    }

    /** French typography (Checkpoint A): non-breaking spaces before ":" ";" "%" and inside « ». */
    public function test_french_typography_uses_non_breaking_spaces(): void
    {
        foreach ($this->flat('fr') as $key => $text) {
            $this->assertDoesNotMatchRegularExpression('/ [;%»]| :(?=\s|$)|« |(?:\d|:megabytes) Mo\b/u', $text, $key);
        }
    }

    /** File sizes: two decimals, at least 0.01 for a non-empty file; "0.01 MB" in English, "0,01 Mo" in French (S4 T1). */
    public function test_file_sizes_are_formatted_per_locale(): void
    {
        $cases = [
            3 * 1024 => ['DOCX file, 0.01 MB.', "Fichier DOCX, 0,01\u{00A0}Mo."],
            9204 => ['DOCX file, 0.01 MB.', "Fichier DOCX, 0,01\u{00A0}Mo."],
            (int) (4.8 * 1048576) => ['DOCX file, 4.80 MB.', "Fichier DOCX, 4,80\u{00A0}Mo."],
        ];
        foreach ($cases as $bytes => [$en, $fr]) {
            $result = $this->fileCheck($bytes);
            $this->assertSame($en, (new MessageCatalog('en'))->finding('file_supported', $result->findingKey, $result->params), "{$bytes} bytes");
            $this->assertSame($fr, (new MessageCatalog('fr'))->finding('file_supported', $result->findingKey, $result->params), "{$bytes} bytes");
        }
        $large = $this->fileCheck((int) (12.35 * 1048576));
        $this->assertSame('The file is 12.35 MB (5 MB at most).', (new MessageCatalog('en'))->finding('file_supported', $large->findingKey, $large->params));
        $this->assertSame("Le fichier fait 12,35\u{00A0}Mo (5\u{00A0}Mo au maximum).", (new MessageCatalog('fr'))->finding('file_supported', $large->findingKey, $large->params));
    }

    private function fileCheck(int $bytes): CheckResult
    {
        $document = new ParsedDocument('docx', 'text', [], null, 1, true, Structure::notInspected(Detection::absent()), $bytes);

        return (new FileSupported)->run(new CheckContext($document, (new SectionDetector)->detect([]), 'en'));
    }
}
