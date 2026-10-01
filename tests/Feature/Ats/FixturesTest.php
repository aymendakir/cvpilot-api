<?php

namespace Tests\Feature\Ats;

use PHPUnit\Framework\AssertionFailedError;
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
        return self::DIR.'/'.(str_contains($file, '/') ? $file : "cvs/{$file}");
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
            $this->assertFileExists(self::DIR.'/'.$name);
        }
        $this->assertContains('invalid/encrypted.pdf', $names);
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

    // --- layout variants (F4, F5, F6, F7) and the R1 spike table PDF --------------------------------

    /** x positions (pt) of the text runs on each page, from the PDF text matrices. */
    private static function pdfRunsX(string $file): array
    {
        $pages = [];
        foreach ((new Parser)->parseFile(self::cv($file))->getPages() as $i => $page) {
            $pages[$i + 1] = array_map(fn ($run) => (float) $run[0][4], $page->getDataTm());
        }

        return $pages;
    }

    public function test_two_column_pdf_has_a_sidebar_of_at_least_eight_lines_on_both_pages(): void
    {
        $pages = self::pdfRunsX('two-column.pdf');

        $this->assertCount(2, $pages);
        foreach ($pages as $number => $xs) {
            // Sidebar text starts at 10 mm (~28 pt), the main column at 76 mm (~215 pt); 70 mm = 198 pt.
            $this->assertGreaterThanOrEqual(8, count(array_filter($xs, fn ($x) => $x < 198)), "page {$number}: sidebar lines");
            $this->assertGreaterThanOrEqual(8, count(array_filter($xs, fn ($x) => $x > 198)), "page {$number}: main column lines");
        }
        $this->assertSame(self::vocabulary(self::docxText('clean-en.docx')), self::vocabulary(self::pdfText('two-column.pdf')));
    }

    public function test_table_layout_docx_puts_the_whole_cv_in_one_table(): void
    {
        $body = self::docxPart('table-layout.docx', 'word/document.xml');

        $this->assertSame(1, substr_count($body, '<w:tbl>'));
        $outside = preg_replace('#<w:tbl>.*</w:tbl>#s', '', $body);
        $this->assertSame('', trim(strip_tags(str_replace('</w:p>', ' ', $outside))), 'no text outside the table');
        $this->assertSame(self::vocabulary(self::docxText('clean-en.docx')), self::vocabulary(self::docxText('table-layout.docx')));
    }

    public function test_table_layout_pdf_has_the_two_grids(): void
    {
        $text = self::pdfText('table-layout.pdf');

        foreach (['Dates', 'Role', 'Company', 'Atlas Commerce, Rabat', 'Agile teamwork'] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
    }

    public function test_scanned_pdf_has_one_image_and_no_text(): void
    {
        $pdf = (new Parser)->parseFile(self::cv('scanned.pdf'));

        $this->assertCount(1, $pdf->getPages());
        $this->assertSame('', trim($pdf->getText()));
        $this->assertCount(1, $pdf->getObjectsByType('XObject', 'Image'));
    }

    public function test_photo_icons_uses_a_small_photo_and_private_use_glyphs_and_the_fixed_variant_uses_labels(): void
    {
        foreach (['photo-icons.docx', 'photo-icons-fixed.docx'] as $file) {
            $body = self::docxPart($file, 'word/document.xml');
            // PhpWord writes images as VML (<w:pict>); Word itself uses <w:drawing>. §4.1 covers both.
            $this->assertSame(1, substr_count($body, '<w:pict'), "{$file}: one image");
            preg_match('/width:([\d.]+)pt; height:([\d.]+)pt/', $body, $m);
            $area = ((float) $m[1] / 28.3465) * ((float) $m[2] / 28.3465); // pt → cm
            $this->assertEqualsWithDelta(16, $area, 0.5, "{$file}: 4 × 4 cm photo");
            $this->assertLessThan(0.15 * 21 * 29.7, $area, 'below the 15 % image rule');
        }

        $icons = self::docxText('photo-icons.docx');
        $this->assertSame(2, preg_match_all('/\p{Co}/u', $icons));
        $this->assertStringContainsString('samir.benali@example.com', $icons);

        $fixed = self::docxText('photo-icons-fixed.docx');
        $this->assertSame(0, preg_match_all('/\p{Co}/u', $fixed));
        $this->assertStringContainsString('Email: samir.benali@example.com', $fixed);
        $this->assertStringContainsString('Phone: +212 600 123 456', $fixed);
    }

    // --- content variants (F8, F9, F12, stuffing) -----------------------------------------------

    public function test_missing_sections_lacks_education_skills_and_phone_and_the_corrected_variant_adds_education(): void
    {
        $missing = self::docxText('missing-sections.docx');
        foreach (["\nEducation\n", "\nSkills\n", '+212'] as $needle) {
            $this->assertStringNotContainsString($needle, $missing);
        }
        $this->assertStringContainsString("\nWork Experience\n", $missing);
        $this->assertGreaterThanOrEqual(250, count(self::words($missing)), 'length still passes');

        $fixed = self::docxText('missing-sections-plus-education.docx');
        $this->assertStringContainsString("\nEducation\n", $fixed);
        $this->assertStringNotContainsString("\nSkills\n", $fixed);
        $this->assertStringNotContainsString('+212', $fixed);
    }

    public function test_pasted_text_has_150_words_a_phone_education_and_skills_but_no_email_experience_or_bullets(): void
    {
        $text = (string) file_get_contents(self::cv('no-email-no-exp.txt'));

        $this->assertEqualsWithDelta(150, count(self::words($text)), 8);
        $this->assertStringContainsString('+212 600 123 456', $text);
        $this->assertStringNotContainsString('@', $text);
        $this->assertDoesNotMatchRegularExpression('/^(work )?experience|projects$/im', $text);
        $this->assertDoesNotMatchRegularExpression('/^\s*[-•*]/m', $text);
        foreach (["\nEducation\n", "\nSkills\n"] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
    }

    public function test_too_long_has_about_1400_words_and_otherwise_passing_content(): void
    {
        $text = self::docxText('too-long.docx');
        preg_match_all('/^• (.+)$/mu', $text, $m);

        $this->assertEqualsWithDelta(1400, count(self::words($text)), 70);
        $this->assertCount(26, $m[1]);
        $this->assertCount(4, array_filter($m[1], fn ($b) => preg_match('/\d/', $b)), 'still 4 quantified bullets');
        foreach (['Redis', 'AWS', 'Kubernetes', 'GraphQL', 'Terraform', 'Vue.js', 'CI/CD'] as $term) {
            $this->assertSame(0, self::occurrences($text, $term), "no new §8.2 keyword: {$term}");
        }
    }

    public function test_stuffing_repeats_laravel_fifteen_times_and_changes_nothing_else(): void
    {
        $stuffed = self::docxText('stuffing.docx');
        $clean = self::docxText('clean-en.docx');

        $this->assertSame(15, self::occurrences($stuffed, 'Laravel'));
        $this->assertSame(self::vocabulary($clean), self::vocabulary($stuffed));
    }

    // --- invalid files (S4 error cases) ----------------------------------------------------------

    public function test_invalid_files_are_what_their_names_say(): void
    {
        $encrypted = (string) file_get_contents(self::cv('invalid/encrypted.pdf'));
        $this->assertStringStartsWith('%PDF-1.4', $encrypted);
        $this->assertStringContainsString('/Encrypt 6 0 R', $encrypted);
        $this->assertStringNotContainsString('Samir', $encrypted, 'the page text is encrypted');

        $this->assertStringStartsWith(hex2bin('D0CF11E0A1B11AE1'), (string) file_get_contents(self::cv('invalid/legacy.doc')));
        $this->assertStringStartsWith('MZ', (string) file_get_contents(self::cv('invalid/renamed-exe.pdf')));

        foreach (['invalid/encrypted.pdf', 'invalid/corrupt.pdf'] as $file) {
            try {
                (new Parser)->parseFile(self::cv($file))->getText();
                $this->fail("{$file} should not be readable by smalot/pdfparser");
            } catch (\Throwable $e) {
                $this->assertNotInstanceOf(AssertionFailedError::class, $e, $e->getMessage());
            }
        }
    }
}
