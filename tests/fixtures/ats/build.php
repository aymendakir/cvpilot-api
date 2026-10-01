<?php

/*
 * Builds the ATS fixtures (SPEC-ats.md §8) from the content files in content/.
 *
 *   php tests/fixtures/ats/build.php          rebuild every fixture
 *   php tests/fixtures/ats/build.php --list   print the fixture names
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
    $path = ATS_FIXTURES."/cvs/{$file}";
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
        parent::_putinfo();
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
    $pdf->Output('F', ATS_FIXTURES."/cvs/{$file}");
}

function ats_pdf_single(string $file, array $blocks): void
{
    $pdf = ats_new_pdf();
    ats_pdf_blocks($pdf, ats_linear($blocks), 18, 174);
    ats_save_pdf($pdf, $file);
}

/* ---------------------------------------------------------------------------------------------
 * Fixtures
 * ------------------------------------------------------------------------------------------- */

/** @return array<string, callable(): void> */
function ats_fixtures(): array
{
    $en = ats_content('base-en');
    $fr = ats_content('base-fr');

    return [
        'clean-en.docx' => fn () => ats_docx_single('clean-en.docx', ats_blocks($en)),
        'clean-en.pdf' => fn () => ats_pdf_single('clean-en.pdf', ats_blocks($en)),
        'clean-fr.docx' => fn () => ats_docx_single('clean-fr.docx', ats_blocks($fr)),
    ];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $fixtures = ats_fixtures();
    if (in_array('--list', $argv, true)) {
        echo implode("\n", array_keys($fixtures))."\n";
        exit(0);
    }
    foreach ($fixtures as $name => $build) {
        $build();
        echo "built {$name}\n";
    }
}
