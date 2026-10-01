<?php

namespace Tests\Feature\Ats;

use App\Services\Ats\Parsing\Confidence;
use App\Services\Ats\Parsing\DocumentReader;
use App\Services\Ats\Parsing\ParsedDocument;
use App\Services\Ats\Parsing\UnreadableDocument;
use FPDF;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `ats-parsing` on every fixture (SPEC-ats.md §4.1, §8): the structure signals each file must produce,
 * the error reasons, the time budget (§8.4) and that parsing leaves no file behind.
 */
class ParsingFixturesTest extends TestCase
{
    private const DIR = __DIR__.'/../../fixtures/ats';

    /** @var list<string> */
    private array $temp = [];

    protected function tearDown(): void
    {
        foreach ($this->temp as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function parse(string $file): ParsedDocument
    {
        return (new DocumentReader)->parseFile(self::DIR.'/'.$file, basename($file));
    }

    /**
     * Expected signals per fixture: detected (true/false/null) and the minimum confidence when detected.
     * Keys: columns, tables, images, textBoxes, headerFooter, glyphIssues.
     *
     * @return array<string, array{0: string, 1: array<string, array{0: ?bool, 1?: Confidence}>, 2: bool}>
     */
    public static function fixtures(): array
    {
        $none = ['columns' => [false], 'tables' => [false], 'images' => [false], 'headerFooter' => [false], 'glyphIssues' => [false]];

        return [
            'F1 clean-en.docx' => ['cvs/clean-en.docx', $none + ['textBoxes' => [false]], true],
            'F2 clean-en.pdf' => ['cvs/clean-en.pdf', $none + ['textBoxes' => [null]], true],
            'F4 two-column.pdf' => ['cvs/two-column.pdf', ['columns' => [true, Confidence::Medium]] + $none, true],
            'F5 table-layout.docx' => ['cvs/table-layout.docx', ['tables' => [true, Confidence::High]] + $none, true],
            'spike table-layout.pdf' => ['cvs/table-layout.pdf', ['tables' => [true, Confidence::Low]] + $none, true],
            'F6 scanned.pdf' => ['cvs/scanned.pdf', ['images' => [true, Confidence::Medium]] + $none, false],
            'F7 photo-icons.docx' => ['cvs/photo-icons.docx', ['images' => [true, Confidence::High], 'glyphIssues' => [true, Confidence::High]] + $none, true],
            'F7 fixed' => ['cvs/photo-icons-fixed.docx', ['images' => [true, Confidence::High]] + $none, true],
            'F8 missing-sections.docx' => ['cvs/missing-sections.docx', $none, true],
            'F8 fixed' => ['cvs/missing-sections-plus-education.docx', $none, true],
            'F11 clean-fr.docx' => ['cvs/clean-fr.docx', $none, true],
            'F12 too-long.docx' => ['cvs/too-long.docx', $none, true],
            'stuffing.docx' => ['cvs/stuffing.docx', $none, true],
        ];
    }

    #[DataProvider('fixtures')]
    public function test_fixture_signals(string $file, array $expected, bool $extractable): void
    {
        $doc = $this->parse($file);

        $this->assertTrue($doc->structure->inspected);
        $this->assertSame($extractable, $doc->textExtractable);
        foreach ($expected as $signal => $want) {
            $detection = $doc->structure->{$signal};
            $this->assertSame($want[0], $detection->detected, "{$file}: {$signal}");
            if (isset($want[1])) {
                $rank = fn (?Confidence $c) => array_search($c, [Confidence::Low, Confidence::Medium, Confidence::High], true);
                $this->assertGreaterThanOrEqual($rank($want[1]), $rank($detection->confidence), "{$file}: {$signal} confidence");
            }
        }
    }

    public function test_two_column_pdf_reports_both_pages_and_column_samples(): void
    {
        $columns = $this->parse('cvs/two-column.pdf')->structure->columns;

        $this->assertSame([1, 2], $columns->extra['pages']);
        $this->assertStringStartsWith('Left column: Samir Benali', $columns->samples[0]);
        $this->assertStringStartsWith('Right column: ', $columns->samples[1]);
    }

    public function test_pdf_and_docx_versions_of_a_cv_give_the_same_text(): void
    {
        $pdf = $this->parse('cvs/clean-en.pdf');
        $docx = $this->parse('cvs/clean-en.docx');

        $this->assertSame(FixturesTest::vocabulary($docx->text), FixturesTest::vocabulary($pdf->text));
        $this->assertSame(2, $pdf->pages);
        $this->assertSame(2, $pdf->lines[array_key_last($pdf->lines)]->page);
        $this->assertNotNull($pdf->lines[0]->x);
    }

    public function test_scanned_pdf_has_no_text_and_one_full_page_image(): void
    {
        $doc = $this->parse('cvs/scanned.pdf');

        $this->assertSame(0, $doc->wordCount);
        $this->assertSame(1, $doc->structure->images->extra['count']);
        $this->assertEqualsWithDelta(100, $doc->structure->images->extra['largest_area_pct'], 0.5);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function invalid(): array
    {
        return [
            'encrypted' => ['invalid/encrypted.pdf', UnreadableDocument::PASSWORD_PROTECTED],
            'corrupt' => ['invalid/corrupt.pdf', UnreadableDocument::CORRUPT],
            'old .doc' => ['invalid/legacy.doc', UnreadableDocument::UNSUPPORTED_TYPE],
            'renamed exe' => ['invalid/renamed-exe.pdf', UnreadableDocument::UNSUPPORTED_TYPE],
        ];
    }

    #[DataProvider('invalid')]
    public function test_invalid_files_are_refused_with_a_reason(string $file, string $reason): void
    {
        try {
            $this->parse($file);
            $this->fail("{$file} must be refused");
        } catch (UnreadableDocument $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    // --- PDF header/footer rule (spike finding) ------------------------------------------------

    /** A small PDF; `$footer` is written in the bottom band of every page. */
    private function pdf(int $pages, string $body, ?string $footer): string
    {
        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->SetAutoPageBreak(false);
        for ($p = 1; $p <= $pages; $p++) {
            $pdf->AddPage();
            $pdf->SetFont('Helvetica', '', 11);
            $pdf->SetXY(20, 30);
            $pdf->MultiCell(170, 6, "{$body} (page {$p})");
            if ($footer !== null) {
                $pdf->SetXY(20, 287);
                $pdf->Cell(170, 5, str_replace('{p}', (string) $p, $footer));
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'ats-pdf');
        $this->temp[] = $path;
        $pdf->Output('F', $path);

        return $path;
    }

    public function test_a_footer_repeated_on_every_page_is_a_footer_and_contact_only_there_is_flagged(): void
    {
        $doc = (new DocumentReader)->parseFile($this->pdf(2, 'Work Experience. Built APIs for clients.', 'jane@example.com - Page {p} of 2'), 'cv.pdf');

        $this->assertTrue($doc->structure->headerFooter->detected);
        $this->assertSame(Confidence::Medium, $doc->structure->headerFooter->confidence);
        $this->assertTrue($doc->structure->headerFooter->extra['contact_only_there']);
    }

    public function test_text_in_the_band_of_a_single_page_pdf_is_not_a_footer(): void
    {
        $doc = (new DocumentReader)->parseFile($this->pdf(1, 'Work Experience. Built APIs for clients.', 'jane@example.com'), 'cv.pdf');

        $this->assertFalse($doc->structure->headerFooter->detected);
        $this->assertSame('Single page: no repeated header or footer', $doc->structure->headerFooter->note);
    }

    public function test_page_numbers_do_not_break_the_repetition_and_no_band_text_means_no_footer(): void
    {
        $doc = (new DocumentReader)->parseFile($this->pdf(2, 'Work Experience.', 'Note {p}: '.str_repeat('x', 3)), 'cv.pdf');
        $this->assertTrue($doc->structure->headerFooter->detected, 'digits are ignored, so "Note 1"/"Note 2" repeat');

        $different = (new DocumentReader)->parseFile($this->pdf(2, 'Work Experience.', null), 'cv.pdf');
        $this->assertFalse($different->structure->headerFooter->detected);
    }

    // --- operations ----------------------------------------------------------------------------

    public function test_a_stuck_binary_times_out_with_a_reason(): void
    {
        $script = tempnam(sys_get_temp_dir(), 'ats-slow');
        $this->temp[] = $script;
        file_put_contents($script, "#!/bin/sh\nsleep 5\n");
        chmod($script, 0700);
        config(['ats.poppler.pdfinfo' => $script, 'ats.poppler.timeout' => 1]);

        try {
            $this->parse('cvs/clean-en.pdf');
            $this->fail('must time out');
        } catch (UnreadableDocument $e) {
            $this->assertSame(UnreadableDocument::TIMEOUT, $e->reason);
        }
    }

    public function test_clean_cvs_parse_within_the_time_budget(): void
    {
        foreach (['cvs/clean-en.docx', 'cvs/clean-en.pdf'] as $file) {
            $started = hrtime(true);
            $this->parse($file);
            $this->assertLessThan(1.5, (hrtime(true) - $started) / 1e9, "{$file}: §8.4 budget for F1/F2");
        }
    }

    public function test_parsing_writes_no_file(): void
    {
        // The system temp dir is listed one level deep only: CI runners keep root-only directories there
        // (systemd), and anything PHP or poppler created for us would sit at its top level. storage/app
        // is ours, so it is walked fully.
        $list = function () {
            $files = array_map(fn ($name) => sys_get_temp_dir().'/'.$name, array_diff(scandir(sys_get_temp_dir()) ?: [], ['.', '..']));
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(storage_path('app'), \FilesystemIterator::SKIP_DOTS)) as $f) {
                $files[] = $f->getPathname();
            }

            return $files;
        };
        $before = $list();
        foreach (self::fixtures() as [$file]) {
            $this->parse($file);
        }
        $this->assertSame([], array_values(array_diff($list(), $before)), 'parsing must not leave files behind (CV photos included)');
    }
}
