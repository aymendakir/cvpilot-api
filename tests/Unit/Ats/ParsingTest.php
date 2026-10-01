<?php

namespace Tests\Unit\Ats;

use App\Services\Ats\Parsing\Confidence;
use App\Services\Ats\Parsing\DocumentReader;
use App\Services\Ats\Parsing\DocumentTypeDetector;
use App\Services\Ats\Parsing\GlyphInspector;
use App\Services\Ats\Parsing\ParsedDocument;
use App\Services\Ats\Parsing\UnreadableDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

/** `ats-parsing` for text and DOCX (SPEC-ats.md §4.1). PDFs follow in T4. */
class ParsingTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../fixtures/ats';

    /** @var list<string> */
    private array $temp = [];

    protected function tearDown(): void
    {
        foreach ($this->temp as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function reader(): DocumentReader
    {
        return new DocumentReader;
    }

    private function fixture(string $file): ParsedDocument
    {
        return $this->reader()->parseFile(self::FIXTURES.'/'.$file, basename($file));
    }

    /**
     * A minimal DOCX with the given body XML and extra parts (header1.xml, footer1.xml).
     *
     * @param  array<string, string>  $parts
     */
    private function docx(string $body, array $parts = [], string $sectPr = ''): string
    {
        $ns = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape"';
        $path = tempnam(sys_get_temp_dir(), 'ats-docx');
        $this->temp[] = $path;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('word/document.xml', "<?xml version=\"1.0\"?><w:document {$ns}><w:body>{$body}<w:sectPr><w:pgSz w:w=\"11906\" w:h=\"16838\"/>{$sectPr}</w:sectPr></w:body></w:document>");
        foreach ($parts as $name => $xml) {
            $zip->addFromString("word/{$name}", "<?xml version=\"1.0\"?><w:hdr {$ns}>{$xml}</w:hdr>");
        }
        $zip->close();

        return $path;
    }

    private static function p(string $text): string
    {
        return '<w:p><w:r><w:t xml:space="preserve">'.htmlspecialchars($text, ENT_XML1).'</w:t></w:r></w:p>';
    }

    // --- type detection -----------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function types(): array
    {
        return [
            'docx' => ['cvs/clean-en.docx', 'docx'],
            'pdf' => ['cvs/clean-en.pdf', 'pdf'],
            'scanned pdf' => ['cvs/scanned.pdf', 'pdf'],
            'encrypted pdf is still a pdf' => ['invalid/encrypted.pdf', 'pdf'],
            'text' => ['cvs/no-email-no-exp.txt', 'text'],
            'old .doc' => ['invalid/legacy.doc', null],
            'exe renamed to .pdf' => ['invalid/renamed-exe.pdf', null],
        ];
    }

    #[DataProvider('types')]
    public function test_type_comes_from_the_content(string $file, ?string $type): void
    {
        $this->assertSame($type, (new DocumentTypeDetector)->detect(self::FIXTURES.'/'.$file, basename($file)));
    }

    public function test_text_is_only_accepted_for_txt_or_extensionless_names(): void
    {
        $path = self::FIXTURES.'/cvs/no-email-no-exp.txt';

        $this->assertSame('text', (new DocumentTypeDetector)->detect($path, 'cv'));
        $this->assertNull((new DocumentTypeDetector)->detect($path, 'cv.html'));
    }

    public function test_unsupported_files_are_refused_with_a_reason(): void
    {
        foreach (['invalid/legacy.doc', 'invalid/renamed-exe.pdf'] as $file) {
            try {
                $this->fixture($file);
                $this->fail("{$file} must be refused");
            } catch (UnreadableDocument $e) {
                $this->assertSame(UnreadableDocument::UNSUPPORTED_TYPE, $e->reason);
            }
        }
    }

    // --- text ---------------------------------------------------------------------------------

    public function test_pasted_text_has_no_inspected_layout_but_glyphs_are_checked(): void
    {
        $doc = $this->fixture('cvs/no-email-no-exp.txt');

        $this->assertSame('text', $doc->type);
        $this->assertSame(150, $doc->wordCount);
        $this->assertFalse($doc->structure->inspected);
        $this->assertNull($doc->structure->columns->detected);
        $this->assertNull($doc->structure->headerFooter->detected);
        $this->assertFalse($doc->structure->glyphIssues->detected);

        $icons = $this->reader()->parseText("Name\n\u{F0E0} name@example.com\nS K I L L S\nPHP");
        $this->assertTrue($icons->structure->glyphIssues->detected);
        $this->assertSame(1, $icons->structure->glyphIssues->extra['private_use']);
        $this->assertSame(1, $icons->structure->glyphIssues->extra['letter_spaced']);
    }

    // --- DOCX fixtures ------------------------------------------------------------------------

    public function test_clean_docx_has_no_structure_issues_and_keeps_reading_order(): void
    {
        $doc = $this->fixture('cvs/clean-en.docx');

        $this->assertSame('docx', $doc->type);
        $this->assertEqualsWithDelta(480, $doc->wordCount, 24);
        $this->assertTrue($doc->structure->inspected);
        foreach (['columns', 'tables', 'images', 'textBoxes', 'headerFooter', 'glyphIssues'] as $signal) {
            $this->assertFalse($doc->structure->{$signal}->detected, $signal);
            $this->assertSame(Confidence::High, $doc->structure->{$signal}->confidence, $signal);
        }
        $lines = array_map(fn ($l) => $l->text, $doc->lines);
        $this->assertSame('Samir Benali', $lines[0]);
        $this->assertLessThan(array_search('Education', $lines, true), array_search('Work Experience', $lines, true));
    }

    public function test_table_layout_docx_reports_a_table_and_keeps_all_text(): void
    {
        $doc = $this->fixture('cvs/table-layout.docx');

        $this->assertTrue($doc->structure->tables->detected);
        $this->assertSame(Confidence::High, $doc->structure->tables->confidence);
        $this->assertSame(1, $doc->structure->tables->extra['count']);
        $this->assertSame($this->fixture('cvs/clean-en.docx')->wordCount, $doc->wordCount);
    }

    public function test_photo_docx_reports_a_small_image_and_icon_glyphs_and_the_fixed_one_only_the_image(): void
    {
        $icons = $this->fixture('cvs/photo-icons.docx')->structure;
        $this->assertTrue($icons->images->detected);
        $this->assertSame(1, $icons->images->extra['count']);
        $this->assertEqualsWithDelta(2.6, $icons->images->extra['largest_area_pct'], 0.3, '4 × 4 cm on A4');
        $this->assertTrue($icons->glyphIssues->detected);
        $this->assertSame(2, $icons->glyphIssues->extra['private_use']);

        $fixed = $this->fixture('cvs/photo-icons-fixed.docx')->structure;
        $this->assertTrue($fixed->images->detected);
        $this->assertFalse($fixed->glyphIssues->detected);
    }

    // --- DOCX features PhpWord does not write --------------------------------------------------

    public function test_section_columns_are_detected(): void
    {
        $doc = $this->reader()->parseFile($this->docx(self::p('Skills'), [], '<w:cols w:num="2" w:space="720"/>'), 'cv.docx');

        $this->assertTrue($doc->structure->columns->detected);
        $this->assertSame('2 text columns in the page layout', $doc->structure->columns->note);
    }

    public function test_text_boxes_are_detected_read_once_and_kept_as_their_own_paragraphs(): void
    {
        $box = '<w:p><w:r><w:t>Anchor</w:t></w:r><w:r><mc:AlternateContent><mc:Choice Requires="wps"><w:drawing><wps:txbx><w:txbxContent>'
            .self::p('Inside the box').'</w:txbxContent></wps:txbx></w:drawing></mc:Choice><mc:Fallback><w:pict><v:textbox><w:txbxContent>'
            .self::p('Inside the box').'</w:txbxContent></v:textbox></w:pict></mc:Fallback></mc:AlternateContent></w:r></w:p>';
        $doc = $this->reader()->parseFile($this->docx($box.self::p('After')), 'cv.docx');

        $this->assertTrue($doc->structure->textBoxes->detected);
        $this->assertSame(1, $doc->structure->textBoxes->extra['count'], 'the VML fallback copy is not counted');
        $this->assertSame(['Anchor', 'Inside the box', 'After'], array_map(fn ($l) => $l->text, $doc->lines));
    }

    public function test_contact_only_in_the_header_is_reported(): void
    {
        $header = self::p('jane@example.com · +212 600 000 000');
        $only = $this->reader()->parseFile($this->docx(self::p('Jane Doe').self::p('Work Experience'), ['header1.xml' => $header]), 'cv.docx');
        $this->assertTrue($only->structure->headerFooter->detected);
        $this->assertTrue($only->structure->headerFooter->extra['contact_only_there']);

        $both = $this->reader()->parseFile($this->docx(self::p('jane@example.com'), ['footer1.xml' => $header]), 'cv.docx');
        $this->assertTrue($both->structure->headerFooter->detected);
        $this->assertFalse($both->structure->headerFooter->extra['contact_only_there']);
    }

    public function test_drawing_images_are_measured_against_the_page(): void
    {
        $image = fn (int $cmW, int $cmH) => '<w:p><w:r><w:drawing><wp:inline><wp:extent cx="'.($cmW * 360000).'" cy="'.($cmH * 360000).'"/><a:graphic><a:graphicData><a:blip/></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
        $doc = $this->reader()->parseFile($this->docx($image(4, 4).$image(18, 10)), 'cv.docx');

        $this->assertSame(2, $doc->structure->images->extra['count']);
        $this->assertEqualsWithDelta(100 * 180 / 623.7, $doc->structure->images->extra['largest_area_pct'], 0.2, '18 × 10 cm on A4');
    }

    public function test_symbol_font_bullets_are_not_glyph_issues_but_contact_icons_are(): void
    {
        $bullet = fn (string $text) => '<w:p><w:r><w:sym w:font="Wingdings" w:char="F0D8"/><w:t xml:space="preserve"> '.$text.'</w:t></w:r></w:p>';
        $doc = $this->reader()->parseFile($this->docx($bullet('Prepared tax returns').$bullet('Reconciled accounts').$bullet('Closed the books')), 'cv.docx');
        $this->assertFalse($doc->structure->glyphIssues->detected);
        $this->assertSame(1, $doc->structure->glyphIssues->extra['symbol_bullets']);

        $mixed = (new GlyphInspector)->inspect(["\u{F0D8} One", "\u{F0D8} Two", "\u{F0D8} Three", "\u{F0E0} jane@example.com"]);
        $this->assertTrue($mixed->detected);
        $this->assertSame(1, $mixed->extra['private_use'], 'the envelope icon counts, the repeated bullet does not');
    }

    public function test_a_broken_docx_is_corrupt(): void
    {
        $path = $this->docx('<w:p><w:r><w:t>unclosed');
        file_put_contents($path, str_replace('</w:body>', '', (string) file_get_contents($path)));
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('word/document.xml', '<w:document><w:body><w:p>');
        $zip->close();

        try {
            $this->reader()->parseFile($path, 'cv.docx');
            $this->fail('must be refused');
        } catch (UnreadableDocument $e) {
            $this->assertSame(UnreadableDocument::CORRUPT, $e->reason);
        }
    }

    public function test_external_entities_are_never_loaded(): void
    {
        $secret = tempnam(sys_get_temp_dir(), 'ats-secret');
        $this->temp[] = $secret;
        file_put_contents($secret, 'TOP-SECRET-VALUE');
        $path = $this->docx(self::p('placeholder'));
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><!DOCTYPE w [<!ENTITY x SYSTEM "file://'.$secret.'">]><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>&x;</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();

        try {
            $doc = $this->reader()->parseFile($path, 'cv.docx');
            $this->assertStringNotContainsString('TOP-SECRET-VALUE', $doc->text);
        } catch (UnreadableDocument $e) {
            $this->assertSame(UnreadableDocument::CORRUPT, $e->reason);
        }
    }
}
