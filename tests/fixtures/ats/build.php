<?php

/*
 * Builds the ATS fixtures (SPEC-ats.md §8) from the content files in content/.
 *
 *   php tests/fixtures/ats/build.php          rebuild every fixture
 *   php tests/fixtures/ats/build.php --list   print the fixture paths (relative to this directory)
 *
 * DOCX files are written with PhpWord, PDFs with FPDF (dev dependency). Dates inside the documents and
 * the ZIP entry times are pinned, so a rebuild produces the same bytes on the same library versions.
 * FixturesTest compares content and structure, not bytes, so a library upgrade does not break it.
 */

require __DIR__.'/../../../vendor/autoload.php';

use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

const ATS_FIXTURES = __DIR__;
const ATS_FIXED_TIME = 1767225600; // 2026-01-01 00:00:00 UTC

/* ---------------------------------------------------------------------------------------------
 * Content → blocks. A block is [kind, text]; every writer renders the same block list, so the
 * DOCX, PDF and text versions of a CV contain the same words.
 * kinds: name, title, contact, heading, role, meta, para, bullet, skill
 * ------------------------------------------------------------------------------------------- */

/** @return array<string, mixed> */
function ats_content(string $name): array
{
    return require ATS_FIXTURES."/content/{$name}.php";
}

/**
 * @param  array<string, mixed>  $c
 * @param  array{phone?: bool, education?: bool, skills?: bool, icons?: bool, contact_labels?: bool}  $o
 * @return array{header: list<array{0: string, 1: string}>, main: list<array{0: string, 1: string}>, side: list<array{0: string, 1: string}>}
 */
function ats_blocks(array $c, array $o = []): array
{
    $o += ['phone' => true, 'education' => true, 'skills' => true, 'icons' => false, 'contact_labels' => false];

    // Private-use code points as icon fonts (Font Awesome) emit them: U+F0E0 envelope, U+F095 phone.
    $email = $o['icons'] ? "\u{F0E0} {$c['email']}" : ($o['contact_labels'] ? "{$c['email_label']}: {$c['email']}" : $c['email']);
    $phone = $o['icons'] ? "\u{F095} {$c['phone']}" : ($o['contact_labels'] ? "{$c['phone_label']}: {$c['phone']}" : $c['phone']);

    $header = [['name', $c['name']], ['title', $c['title']]];
    $contact = [['contact', $email]];
    if ($o['phone']) {
        $contact[] = ['contact', $phone];
    }
    $contact[] = ['contact', $c['location']];

    $main = [['heading', $c['summary_heading']], ['para', $c['summary']], ['heading', $c['experience_heading']]];
    foreach ($c['experience'] as $job) {
        $main[] = ['role', "{$job['role']}, {$job['company']}, {$job['place']}"];
        $main[] = ['meta', $job['dates']];
        $main[] = ['para', $job['about']];
        foreach ($job['bullets'] as $bullet) {
            $main[] = ['bullet', $bullet];
        }
    }
    if ($o['education']) {
        $main[] = ['heading', $c['education_heading']];
        foreach ($c['education'] as $school) {
            $main[] = ['role', "{$school['degree']}, {$school['school']}"];
            $main[] = ['meta', $school['dates']];
            $main[] = ['para', $school['detail']];
        }
    }

    $side = [];
    if ($o['skills']) {
        $side[] = ['heading', $c['skills_heading']];
        foreach ($c['skills'] as $skill) {
            $side[] = ['skill', $skill];
        }
    }

    return ['header' => $header, 'contact' => $contact, 'main' => $main, 'side' => $side];
}

/** Single-column reading order: header, contact, main, skills. */
function ats_linear(array $blocks): array
{
    return array_merge($blocks['header'], $blocks['contact'], $blocks['main'], $blocks['side']);
}

function ats_plain(array $blocks): string
{
    $lines = [];
    foreach (ats_linear($blocks) as [$kind, $text]) {
        if ($kind === 'heading' && $lines !== []) {
            $lines[] = '';
        }
        $lines[] = $kind === 'bullet' ? "- {$text}" : $text;
    }

    return implode("\n", $lines)."\n";
}

/* ---------------------------------------------------------------------------------------------
 * DOCX
 * ------------------------------------------------------------------------------------------- */

function ats_new_word(): PhpWord
{
    $word = new PhpWord;
    $word->getDocInfo()->setCreator('CVPilot fixtures')->setCreated(ATS_FIXED_TIME)->setModified(ATS_FIXED_TIME);
    $word->setDefaultFontName('Calibri');
    $word->setDefaultFontSize(10.5);

    return $word;
}

/** @param  AbstractContainer  $box */
function ats_docx_blocks($box, array $blocks, string $iconFont = ''): void
{
    foreach ($blocks as [$kind, $text]) {
        $font = match ($kind) {
            'name' => ['size' => 20, 'bold' => true],
            'title' => ['size' => 12],
            'heading' => ['size' => 13, 'bold' => true, 'allCaps' => false],
            'role' => ['bold' => true],
            'meta' => ['italic' => true],
            default => [],
        };
        $paragraph = ['spaceAfter' => $kind === 'heading' ? 60 : 40, 'spaceBefore' => $kind === 'heading' ? 160 : 0];
        if ($kind === 'bullet') {
            $box->addText("• {$text}", $font, $paragraph + ['indentation' => ['left' => 280, 'hanging' => 200]]);
        } elseif ($kind === 'contact' && $iconFont !== '' && preg_match('/^(\p{Co}) (.*)$/u', $text, $m)) {
            $run = $box->addTextRun($paragraph);
            $run->addText($m[1], ['name' => $iconFont]);
            $run->addText(' '.$m[2]);
        } else {
            $box->addText($text, $font, $paragraph);
        }
    }
}

function ats_save_docx(PhpWord $word, string $file): void
{
    $path = ATS_FIXTURES."/{$file}";
    IOFactory::createWriter($word, 'Word2007')->save($path);
    ats_pin_zip($path);
}

/** Pins every ZIP entry's modification time so a rebuild gives the same bytes. */
function ats_pin_zip(string $path): void
{
    $zip = new ZipArchive;
    $zip->open($path);
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $zip->setMtimeIndex($i, ATS_FIXED_TIME);
    }
    $zip->close();
}

function ats_docx_single(string $file, array $blocks): void
{
    $word = ats_new_word();
    $section = $word->addSection(['marginLeft' => 1000, 'marginRight' => 1000, 'marginTop' => 900, 'marginBottom' => 900]);
    ats_docx_blocks($section, ats_linear($blocks));
    ats_save_docx($word, $file);
}

/* ---------------------------------------------------------------------------------------------
 * PDF
 * ------------------------------------------------------------------------------------------- */

final class AtsPdf extends FPDF
{
    // FPDF stamps time() in _enddoc(); pin it where it is written.
    protected function _putinfo()
    {
        $this->CreationDate = ATS_FIXED_TIME;
        // Kept from S0 so the fixture bytes stay stable: smalot/pdfparser (removed in S1) treated a
        // Producer starting with "FPDF" as an FPDI import and failed to read text positions.
        $this->metadata['Producer'] = 'CVPilot fixture builder (FPDF '.self::VERSION.')';
        parent::_putinfo();
    }

    /** Go back to an earlier page to fill a second column (FPDF appends to the current page). */
    public function goToPage(int $page): void
    {
        $this->page = $page;
    }
}

/** FPDF core fonts are Windows-1252. */
function ats_pdf_text(string $text): string
{
    return iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
}

function ats_new_pdf(): AtsPdf
{
    $pdf = new AtsPdf('P', 'mm', 'A4');
    $pdf->SetCreator('CVPilot fixtures');
    $pdf->SetMargins(18, 16, 18);
    $pdf->SetAutoPageBreak(true, 16);
    $pdf->AddPage();

    return $pdf;
}

/** Writes blocks into a column of the given x and width, starting at the current y. */
function ats_pdf_blocks(AtsPdf $pdf, array $blocks, float $x, float $width): void
{
    // Wrapped lines and page breaks return to the left margin, so the column's x is the margin.
    $pdf->SetLeftMargin($x);
    foreach ($blocks as [$kind, $text]) {
        [$style, $size, $height] = match ($kind) {
            'name' => ['B', 18, 9],
            'title' => ['', 12, 7],
            'heading' => ['B', 12.5, 7],
            'role' => ['B', 10.5, 5.2],
            'meta' => ['I', 10, 5],
            default => ['', 10, 5],
        };
        if ($kind === 'heading') {
            $pdf->Ln(3);
        }
        $pdf->SetFont('Helvetica', $style, $size);
        $pdf->SetX($x);
        if ($kind === 'bullet') {
            $pdf->Cell(4, $height, ats_pdf_text('•'));
            $pdf->MultiCell($width - 4, $height, ats_pdf_text($text));
        } else {
            $pdf->MultiCell($width, $height, ats_pdf_text($text));
        }
    }
}

function ats_save_pdf(AtsPdf $pdf, string $file): void
{
    $pdf->Output('F', ATS_FIXTURES."/{$file}");
}

function ats_pdf_single(string $file, array $blocks): void
{
    $pdf = ats_new_pdf();
    ats_pdf_blocks($pdf, ats_linear($blocks), 18, 174);
    ats_save_pdf($pdf, $file);
}

/* ---------------------------------------------------------------------------------------------
 * Layout variants
 * ------------------------------------------------------------------------------------------- */

/**
 * F4: name, contact and skills in a 52 mm left sidebar, everything else in the right column, two
 * pages. The skills list is split so the sidebar has at least 8 lines on each page (§4.1 threshold).
 */
function ats_pdf_two_column(string $file, array $blocks): void
{
    $pdf = ats_new_pdf();
    $pdf->SetAutoPageBreak(true, 45); // leaves a page-2 main column of at least 8 lines
    $top = 16;
    $pdf->SetFillColor(236, 240, 244);
    $pdf->Rect(0, 0, 70, 297, 'F');

    $pdf->SetY($top);
    ats_pdf_blocks($pdf, $blocks['main'], 76, 116);
    $pages = $pdf->PageNo();
    for ($p = 2; $p <= $pages; $p++) {
        $pdf->goToPage($p);
        $pdf->Rect(0, 0, 70, 297, 'F');
    }

    $side = $blocks['side'];
    $first = array_merge($blocks['header'], $blocks['contact'], array_slice($side, 0, 9));
    $pdf->goToPage(1);
    $pdf->SetY($top);
    ats_pdf_blocks($pdf, $first, 10, 52);
    $pdf->goToPage(2);
    $pdf->SetY($top);
    ats_pdf_blocks($pdf, array_slice($side, 9), 10, 52);

    $pdf->goToPage($pages);
    ats_save_pdf($pdf, $file);
}

/** F5: the whole CV inside a two-column Word table (sidebar cell + main cell). */
function ats_docx_table(string $file, array $blocks): void
{
    $word = ats_new_word();
    $section = $word->addSection(['marginLeft' => 700, 'marginRight' => 700, 'marginTop' => 700, 'marginBottom' => 700]);
    $table = $section->addTable(['borderSize' => 6, 'borderColor' => 'BBBBBB', 'cellMargin' => 100]);
    $table->addRow();
    ats_docx_blocks($table->addCell(3000), array_merge($blocks['header'], $blocks['contact'], $blocks['side']));
    ats_docx_blocks($table->addCell(7600), $blocks['main']);
    ats_save_docx($word, $file);
}

/**
 * Spike only (risk R1): a PDF with ruled tables. The work history summary (3 columns) and the skills
 * (4 columns) are grids; the bullets follow as normal paragraphs.
 */
function ats_pdf_tables(string $file, array $c, array $blocks): void
{
    $pdf = ats_new_pdf();
    ats_pdf_blocks($pdf, array_merge($blocks['header'], $blocks['contact']), 18, 174);

    $grid = function (array $rows, array $widths) use ($pdf) {
        $pdf->SetLeftMargin(18);
        $pdf->SetX(18);
        foreach ($rows as $i => $row) {
            $pdf->SetFont('Helvetica', $i === 0 ? 'B' : '', 9.5);
            foreach ($row as $col => $cell) {
                $pdf->Cell($widths[$col], 7, ats_pdf_text($cell), 1);
            }
            $pdf->Ln();
        }
    };

    ats_pdf_blocks($pdf, [['heading', $c['experience_heading']]], 18, 174);
    $rows = [['Dates', 'Role', 'Company']];
    foreach ($c['experience'] as $job) {
        $rows[] = [$job['dates'], $job['role'], "{$job['company']}, {$job['place']}"];
    }
    $grid($rows, [48, 58, 68]);
    $bullets = [];
    foreach ($c['experience'] as $job) {
        $bullets[] = ['role', "{$job['role']}, {$job['company']}"];
        foreach ($job['bullets'] as $bullet) {
            $bullets[] = ['bullet', $bullet];
        }
    }
    ats_pdf_blocks($pdf, $bullets, 18, 174);

    ats_pdf_blocks($pdf, [['heading', $c['skills_heading']]], 18, 174);
    $grid(array_chunk($c['skills'], 4), [43.5, 43.5, 43.5, 43.5]);
    ats_save_pdf($pdf, $file);
}

/**
 * F6: a "scanned" CV: each page is one image of the text, with no text layer. GD's built-in font is
 * enough; the point is that nothing can be extracted.
 */
function ats_pdf_scanned(string $file, string $text): void
{
    $lines = [];
    foreach (explode("\n", $text) as $line) {
        foreach (explode("\n", wordwrap($line, 88, "\n", true)) as $wrapped) {
            $lines[] = $wrapped;
        }
    }
    $image = imagecreatetruecolor(827, 1169); // A4 at 100 dpi
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    $ink = imagecolorallocate($image, 30, 30, 30);
    foreach (array_slice($lines, 0, 72) as $i => $line) {
        imagestring($image, 3, 40, 40 + $i * 15, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $line), $ink);
    }
    $png = tempnam(sys_get_temp_dir(), 'ats-scan').'.png';
    imagepng($image, $png, 9);
    imagedestroy($image);
    try {
        $pdf = new AtsPdf('P', 'mm', 'A4');
        $pdf->SetCreator('CVPilot fixtures');
        $pdf->AddPage();
        $pdf->Image($png, 0, 0, 210, 297);
        ats_save_pdf($pdf, $file);
    } finally {
        @unlink($png);
    }
}

/** A plain grey head-and-shoulders silhouette for F7 (no real face). */
function ats_photo_png(): string
{
    $image = imagecreatetruecolor(400, 400);
    imagefill($image, 0, 0, imagecolorallocate($image, 210, 216, 222));
    $grey = imagecolorallocate($image, 120, 130, 140);
    imagefilledellipse($image, 200, 150, 150, 170, $grey);
    imagefilledellipse($image, 200, 400, 320, 260, $grey);
    $png = tempnam(sys_get_temp_dir(), 'ats-photo').'.png';
    imagepng($image, $png, 9);
    imagedestroy($image);

    return $png;
}

/** F7: a 4 cm profile photo above a single-column CV; contact lines may use icon-font glyphs. */
function ats_docx_photo(string $file, array $blocks): void
{
    $png = ats_photo_png();
    try {
        $word = ats_new_word();
        $section = $word->addSection(['marginLeft' => 1000, 'marginRight' => 1000, 'marginTop' => 900, 'marginBottom' => 900]);
        $section->addImage($png, ['width' => 113, 'height' => 113]); // 4 cm = 113 pt
        ats_docx_blocks($section, ats_linear($blocks), 'FontAwesome');
        ats_save_docx($word, $file);
    } finally {
        @unlink($png);
    }
}

/* ---------------------------------------------------------------------------------------------
 * Invalid files (S4 error cases)
 * ------------------------------------------------------------------------------------------- */

function ats_write(string $file, string $bytes): void
{
    file_put_contents(ATS_FIXTURES."/{$file}", $bytes);
}

function ats_rc4(string $key, string $data): string
{
    $s = range(0, 255);
    for ($i = 0, $j = 0; $i < 256; $i++) {
        $j = ($j + $s[$i] + ord($key[$i % strlen($key)])) % 256;
        [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
    }
    $out = '';
    for ($n = 0, $i = 0, $j = 0; $n < strlen($data); $n++) {
        $i = ($i + 1) % 256;
        $j = ($j + $s[$i]) % 256;
        [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
        $out .= chr(ord($data[$n]) ^ $s[($s[$i] + $s[$j]) % 256]);
    }

    return $out;
}

/**
 * A one-page PDF protected with a user password ("open password"), written by hand because FPDF has no
 * encryption: Standard security handler, revision 2, 40-bit RC4 (PDF 1.7 §7.6.3, algorithms 2, 3, 4).
 * Without the password nothing can be read; `pdftotext -upw secret` opens it.
 */
function ats_encrypted_pdf(string $userPassword = 'secret', string $ownerPassword = 'owner-secret'): string
{
    $pad = hex2bin('28BF4E5E4E758A4164004E56FFFA01082E2E00B6D0683E802F0CA9FE6453697A');
    $padded = fn (string $pw) => substr($pw.$pad, 0, 32);
    $id = md5('cvpilot-ats-encrypted-fixture', true);
    $p = -44; // print + copy forbidden etc.; any value works for this fixture

    $o = ats_rc4(substr(md5($padded($ownerPassword), true), 0, 5), $padded($userPassword));
    $key = substr(md5($padded($userPassword).$o.pack('V', $p).$id, true), 0, 5);
    $u = ats_rc4($key, $pad);
    $objectKey = fn (int $num) => substr(md5($key.substr(pack('V', $num), 0, 3)."\0\0", true), 0, 10);

    $content = ats_rc4($objectKey(4), "BT /F1 14 Tf 72 760 Td (Samir Benali - Backend Developer) Tj ET\n");
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
        4 => '<< /Length '.strlen($content)." >>\nstream\n".$content."\nendstream",
        5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        6 => '<< /Filter /Standard /V 1 /R 2 /O <'.bin2hex($o).'> /U <'.bin2hex($u).'> /P '.$p.' >>',
    ];
    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];
    foreach ($objects as $num => $body) {
        $offsets[$num] = strlen($pdf);
        $pdf .= "{$num} 0 obj\n{$body}\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 7\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    $hexId = bin2hex($id);

    return $pdf."trailer\n<< /Size 7 /Root 1 0 R /Encrypt 6 0 R /ID [<{$hexId}> <{$hexId}>] >>\nstartxref\n{$xref}\n%%EOF\n";
}

/* ---------------------------------------------------------------------------------------------
 * Fixtures
 * ------------------------------------------------------------------------------------------- */

/** @return array<string, callable(string): void> path relative to this directory => builder */
function ats_fixtures(): array
{
    $en = ats_content('base-en');
    $fr = ats_content('base-fr');

    $long = ats_content('long-en');
    $stuffed = $en;
    $stuffed['skills'][] = implode(', ', array_fill(0, 12, 'Laravel')); // 3 + 12 = 15 occurrences

    return [
        // F1, F3, F13 / F2 and the corrected F4 / F11
        'cvs/clean-en.docx' => fn ($f) => ats_docx_single($f, ats_blocks($en)),
        'cvs/clean-en.pdf' => fn ($f) => ats_pdf_single($f, ats_blocks($en)),
        'cvs/clean-fr.docx' => fn ($f) => ats_docx_single($f, ats_blocks($fr)),
        // F4, F4b
        'cvs/two-column.pdf' => fn ($f) => ats_pdf_two_column($f, ats_blocks($en)),
        // F5 (corrected variant: clean-en.docx)
        'cvs/table-layout.docx' => fn ($f) => ats_docx_table($f, ats_blocks($en)),
        // R1 spike only
        'cvs/table-layout.pdf' => fn ($f) => ats_pdf_tables($f, $en, ats_blocks($en)),
        // F6
        'cvs/scanned.pdf' => fn ($f) => ats_pdf_scanned($f, ats_plain(ats_blocks($en))),
        // F7 and its corrected variant
        'cvs/photo-icons.docx' => fn ($f) => ats_docx_photo($f, ats_blocks($en, ['icons' => true])),
        'cvs/photo-icons-fixed.docx' => fn ($f) => ats_docx_photo($f, ats_blocks($en, ['contact_labels' => true])),
        // F8 and its corrected variant
        'cvs/missing-sections.docx' => fn ($f) => ats_docx_single($f, ats_blocks($en, ['phone' => false, 'education' => false, 'skills' => false])),
        'cvs/missing-sections-plus-education.docx' => fn ($f) => ats_docx_single($f, ats_blocks($en, ['phone' => false, 'skills' => false])),
        // F9 (pasted text, written by hand in content/)
        'cvs/no-email-no-exp.txt' => fn ($f) => ats_write($f, (string) file_get_contents(ATS_FIXTURES.'/content/no-email-no-exp.txt')),
        // F12
        'cvs/too-long.docx' => fn ($f) => ats_docx_single($f, ats_blocks($long)),
        // §8.4 stuffing
        'cvs/stuffing.docx' => fn ($f) => ats_docx_single($f, ats_blocks($stuffed)),
        // S4 error cases (the 16 MB file is generated at test time, never committed)
        'invalid/encrypted.pdf' => fn ($f) => ats_write($f, ats_encrypted_pdf()),
        'invalid/corrupt.pdf' => fn ($f) => ats_write($f, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R\n% truncated: no pages, no xref, no trailer\n"),
        'invalid/legacy.doc' => fn ($f) => ats_write($f, hex2bin('D0CF11E0A1B11AE1').str_repeat("\0", 504)),
        'invalid/renamed-exe.pdf' => fn ($f) => ats_write($f, 'MZ'.str_repeat("\0", 58).pack('V', 64).'PE'."\0\0".str_repeat("\0", 60)),
    ];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $fixtures = ats_fixtures();
    if (in_array('--list', $argv, true)) {
        echo implode("\n", array_keys($fixtures))."\n";
        exit(0);
    }
    foreach ($fixtures as $name => $build) {
        $build($name);
        echo "built {$name}\n";
    }
}
