<?php

namespace Tests\Feature\Ats;

use PHPUnit\Framework\Attributes\DataProvider;
use Smalot\PdfParser\Parser;
use Tests\TestCase;
use ZipArchive;

/**
 * The ATS fixtures (SPEC-ats.md §8) are what they claim to be. This checks the committed files with
 * today's libraries, independent of the engine that S1–S4 build: a later golden test can then trust
 * that, for example, two-column.pdf really has a sidebar and F3's CV really lacks Redis.
 */
class FixturesTest extends TestCase
{
    private const DIR = __DIR__.'/../../fixtures/ats';

    public static function cv(string $file): string
    {
        return self::DIR.'/cvs/'.$file;
    }

    /** Paragraph text of a DOCX body, one paragraph per line. */
    public static function docxText(string $file): string
    {
        return self::docxPart($file, 'word/document.xml', text: true);
    }

    public static function docxPart(string $file, string $part, bool $text = false): string
    {
        $zip = new ZipArchive;
        self::assertTrue($zip->open(self::cv($file)) === true, "{$file} must be a ZIP");
        $xml = (string) $zip->getFromName($part);
        $zip->close();
        if (! $text) {
            return $xml;
        }
        $xml = preg_replace('#</w:p>#', "\n", $xml);

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    public static function pdfText(string $file): string
    {
        return (new Parser)->parseFile(self::cv($file))->getText();
    }

    /** @return list<string> */
    public static function words(string $text): array
    {
        return preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
    }

    /** Lower-case letter/digit tokens, as a sorted unique list (layout-independent comparison). */
    public static function vocabulary(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $m);
        $tokens = array_values(array_unique($m[0]));
        sort($tokens);

        return $tokens;
    }

    public static function occurrences(string $text, string $term): int
    {
        return preg_match_all('/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{N}])/iu', $text);
    }

    // --- build -------------------------------------------------------------------------------

    public function test_every_fixture_listed_by_build_php_is_committed(): void
    {
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(self::DIR.'/build.php').' --list', $names, $code);

        $this->assertSame(0, $code);
        $this->assertNotEmpty($names);
        foreach ($names as $name) {
            $this->assertFileExists(self::cv($name));
        }
    }

    // --- clean fixtures (F1, F2, F3, F11, F13) ----------------------------------------------------

    /** @return array<string, array{0: string, 1: list<string>, 2: list<string>}> */
    public static function keywordFacts(): array
    {
        $en = [
            ['PHP', 'Laravel', 'MySQL', 'Docker', 'PHPUnit', 'RESTful API', 'GitHub', 'continuous integration', 'Vue'],
            ['Redis', 'AWS', 'Kubernetes', 'GraphQL', 'Terraform', 'Symfony', 'Git', 'REST', 'CI/CD', 'Vue.js'],
        ];

        return [
            'clean-en.docx' => ['clean-en.docx', ...$en],
            'clean-en.pdf' => ['clean-en.pdf', ...$en],
            'clean-fr.docx' => ['clean-fr.docx', ['PHP', 'Symfony', 'MySQL', 'Docker', 'gestion de projets', 'tests unitaires'], ['Vue', 'Vue.js']],
        ];
    }

    #[DataProvider('keywordFacts')]
    public function test_clean_cvs_contain_exactly_the_planned_keywords(string $file, array $present, array $absent): void
    {
        $text = str_ends_with($file, '.pdf') ? self::pdfText($file) : self::docxText($file);

        foreach ($present as $term) {
            $this->assertGreaterThan(0, self::occurrences($text, $term), "{$file} must contain {$term}");
        }
        foreach ($absent as $term) {
            $this->assertSame(0, self::occurrences($text, $term), "{$file} must not contain {$term}");
        }
        $this->assertLessThanOrEqual(10, self::occurrences($text, 'Laravel'), 'below the stuffing threshold');
    }

    public function test_clean_cvs_have_about_480_words_and_the_same_content_in_every_format(): void
    {
        $docx = self::docxText('clean-en.docx');
        $pdf = self::pdfText('clean-en.pdf');

        foreach (['clean-en.docx' => $docx, 'clean-en.pdf' => $pdf, 'clean-fr.docx' => self::docxText('clean-fr.docx')] as $file => $text) {
            $this->assertEqualsWithDelta(480, count(self::words($text)), 24, "{$file}: ~480 words (±5 %)");
        }
        $this->assertSame(self::vocabulary($docx), self::vocabulary($pdf));
        $this->assertCount(2, (new Parser)->parseFile(self::cv('clean-en.pdf'))->getPages());
    }

    public function test_clean_cvs_have_ten_action_verb_bullets_four_of_them_quantified(): void
    {
        foreach (['clean-en.docx', 'clean-fr.docx'] as $file) {
            preg_match_all('/^• (.+)$/mu', self::docxText($file), $m);

            $this->assertCount(10, $m[1], "{$file}: 10 bullets");
            $this->assertCount(4, array_filter($m[1], fn ($b) => preg_match('/\d/', $b)), "{$file}: 4 quantified bullets");
        }
    }

    public function test_clean_cvs_have_the_three_sections_and_the_contact_details(): void
    {
        $en = self::docxText('clean-en.docx');
        foreach (["\nWork Experience\n", "\nEducation\n", "\nSkills\n", 'samir.benali@example.com', '+212 600 123 456'] as $needle) {
            $this->assertStringContainsString($needle, $en);
        }
        $fr = self::docxText('clean-fr.docx');
        foreach (["\nExpérience professionnelle\n", "\nFormation\n", "\nCompétences\n"] as $needle) {
            $this->assertStringContainsString($needle, $fr);
        }
        foreach (['clean-en.docx', 'clean-fr.docx'] as $file) {
            $body = self::docxPart($file, 'word/document.xml');
            $this->assertStringNotContainsString('<w:tbl>', $body, "{$file}: no table");
            $this->assertStringNotContainsString('<w:drawing', $body, "{$file}: no image");
        }
    }
}
