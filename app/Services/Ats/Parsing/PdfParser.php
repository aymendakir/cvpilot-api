<?php

namespace App\Services\Ats\Parsing;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * PDF → ParsedDocument with poppler (docs/ats-spike-s0.md): `pdfinfo` for pages and encryption,
 * `pdftotext -bbox-layout` for the text and line boxes, `pdfimages -list` for images. Nothing is written
 * to disk; every call has a hard timeout (Poppler::exec).
 *
 * Positions are converted to PDF space (points, origin bottom-left) so the §4.1 thresholds read the same
 * as in the spec.
 */
final class PdfParser implements DocumentParser
{
    private const EMAIL = '/[\p{L}\p{N}._%+-]+@[\p{L}\p{N}.-]+\.\p{L}{2,}/u';

    private const PHONE = '/\+?\d[\d ().-]{7,}\d/';

    public function __construct(
        private readonly Poppler $poppler = new Poppler,
        private readonly GlyphInspector $glyphs = new GlyphInspector,
    ) {}

    public function parse(string $path): ParsedDocument
    {
        $info = $this->info($path);
        $pages = $this->pages($path);

        $lines = [];
        foreach ($pages as $page) {
            foreach ($page['lines'] as $line) {
                $lines[] = new Line($line['text'], $page['number'], $line['x'], $line['y']);
            }
        }
        $text = implode("\n", array_map(fn (Line $l) => $l->text, $lines));
        $words = ParsedDocument::countWords($text);

        return new ParsedDocument(
            type: 'pdf',
            text: $text,
            lines: $lines,
            pages: $info['pages'],
            wordCount: $words,
            textExtractable: $words > 0,
            structure: new Structure(
                inspected: true,
                columns: $this->columns($pages),
                tables: $this->tables($pages),
                images: $this->images($path, $info['width'] * $info['height']),
                textBoxes: Detection::notInspected('Not applicable to PDF'),
                headerFooter: $this->headerFooter($pages),
                glyphIssues: $this->glyphs->inspect(array_map(fn (Line $l) => $l->text, $lines)),
            ),
            sizeBytes: filesize($path) ?: null,
        );
    }

    /** @return array{pages: int, width: float, height: float} */
    private function info(string $path): array
    {
        $result = $this->poppler->exec('pdfinfo', [], $path);
        if ($result['exit'] !== 0) {
            throw new UnreadableDocument(
                str_contains($result['error'], 'Incorrect password') ? UnreadableDocument::PASSWORD_PROTECTED : UnreadableDocument::CORRUPT,
                trim($result['error']),
            );
        }
        preg_match('/^Pages:\s+(\d+)/m', $result['output'], $pages);
        preg_match('/^Page size:\s+([\d.]+) x ([\d.]+)/m', $result['output'], $size);

        return ['pages' => (int) ($pages[1] ?? 0), 'width' => (float) ($size[1] ?? 595.28), 'height' => (float) ($size[2] ?? 841.89)];
    }

    /**
     * Pages with their lines, in poppler's reading order. Each line: text, x (left), y (baseline-ish
     * bottom, PDF space), w (width), size (line height).
     *
     * @return list<array{number: int, width: float, height: float, lines: list<array{text: string, x: float, y: float, w: float, size: float}>}>
     */
    private function pages(string $path): array
    {
        $result = $this->poppler->exec('pdftotext', ['-bbox-layout', '-enc', 'UTF-8', '-q'], $path);
        if ($result['exit'] !== 0) {
            throw new UnreadableDocument(UnreadableDocument::CORRUPT, trim($result['error']));
        }
        $doc = new DOMDocument;
        // The output declares an external XHTML DTD; LIBXML_NONET keeps libxml from fetching it.
        if (! @$doc->loadXML($result['output'], LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw new UnreadableDocument(UnreadableDocument::CORRUPT, 'unreadable pdftotext output');
        }
        $x = new DOMXPath($doc);
        $x->registerNamespace('h', 'http://www.w3.org/1999/xhtml');

        $pages = [];
        foreach ($x->query('//h:page') as $n => $page) {
            /** @var DOMElement $page */
            $height = (float) $page->getAttribute('height');
            $lines = [];
            foreach ($x->query('.//h:line', $page) as $line) {
                /** @var DOMElement $line */
                $words = [];
                foreach ($x->query('h:word', $line) as $word) {
                    $words[] = $word->textContent;
                }
                $text = trim(implode(' ', $words));
                if ($text === '') {
                    continue;
                }
                $xMin = (float) $line->getAttribute('xMin');
                $lines[] = [
                    'text' => $text,
                    'x' => $xMin,
                    'y' => $height - (float) $line->getAttribute('yMax'),
                    'w' => (float) $line->getAttribute('xMax') - $xMin,
                    'size' => (float) $line->getAttribute('yMax') - (float) $line->getAttribute('yMin'),
                ];
            }
            $pages[] = ['number' => $n + 1, 'width' => (float) $page->getAttribute('width'), 'height' => $height, 'lines' => $lines];
        }

        return $pages;
    }

    /**
     * Rows (same baseline ± 2 pt), split into segments where a line starts far right of where the
     * previous one ended. Segment starts are the "line starts" of §4.1.
     *
     * @return list<array{y: float, segments: list<array{x: float, end: float, text: string}>}>
     */
    private function rows(array $lines): array
    {
        usort($lines, fn ($a, $b) => [-$a['y'], $a['x']] <=> [-$b['y'], $b['x']]);
        $rows = [];
        foreach ($lines as $line) {
            $last = array_key_last($rows);
            if ($last !== null && abs($rows[$last]['y'] - $line['y']) <= 2) {
                $rows[$last]['lines'][] = $line;
            } else {
                $rows[] = ['y' => $line['y'], 'lines' => [$line]];
            }
        }
        $out = [];
        foreach ($rows as $row) {
            usort($row['lines'], fn ($a, $b) => $a['x'] <=> $b['x']);
            $segments = [];
            foreach ($row['lines'] as $line) {
                $s = array_key_last($segments);
                if ($s !== null && $line['x'] <= $segments[$s]['end'] + max(3 * $line['size'], 15)) {
                    $segments[$s]['end'] = max($segments[$s]['end'], $line['x'] + $line['w']);
                    $segments[$s]['text'] .= ' '.$line['text'];
                } else {
                    $segments[] = ['x' => $line['x'], 'end' => $line['x'] + $line['w'], 'text' => $line['text']];
                }
            }
            $out[] = ['y' => $row['y'], 'segments' => $segments];
        }

        return $out;
    }

    /** §4.1 columns, per page; the document result is the most confident page. */
    private function columns(array $pages): Detection
    {
        $c = config('ats.structure');
        $found = [];
        foreach ($pages as $page) {
            $starts = [];
            foreach ($this->rows($page['lines']) as $row) {
                foreach ($row['segments'] as $segment) {
                    $starts[] = ['x' => $segment['x'], 'y' => $row['y'], 'text' => $segment['text']];
                }
            }
            usort($starts, fn ($a, $b) => $a['x'] <=> $b['x']);
            $clusters = [];
            foreach ($starts as $start) {
                $k = array_key_last($clusters);
                if ($k !== null && $start['x'] - $clusters[$k]['last'] <= 12) {
                    $clusters[$k]['points'][] = $start;
                    $clusters[$k]['last'] = $start['x'];
                } else {
                    $clusters[] = ['first' => $start['x'], 'last' => $start['x'], 'points' => [$start]];
                }
            }

            $best = null;
            foreach ($clusters as $i => $a) {
                foreach (array_slice($clusters, $i + 1) as $b) {
                    $lines = min(count($a['points']), count($b['points']));
                    if ($lines < 5 || $b['first'] - $a['first'] < $c['column_gap'] * $page['width']) {
                        continue;
                    }
                    $ya = array_column($a['points'], 'y');
                    $yb = array_column($b['points'], 'y');
                    $overlap = max(0, min(max($ya), max($yb)) - max(min($ya), min($yb)));
                    $ratio = $overlap / (min(max($ya) - min($ya), max($yb) - min($yb)) ?: 1);
                    if ($ratio < $c['column_overlap']) {
                        continue;
                    }
                    $confidence = $lines < $c['column_min_lines'] ? Confidence::Low
                        : ($lines >= $c['column_high_lines'] && $ratio >= $c['column_high_overlap'] ? Confidence::High : Confidence::Medium);
                    if ($best === null || $this->rank($confidence) > $this->rank($best['confidence'])) {
                        $best = ['confidence' => $confidence, 'left' => $a, 'right' => $b];
                    }
                }
            }
            if ($best !== null) {
                $found[$page['number']] = $best;
            }
        }
        if ($found === []) {
            return Detection::absent(Confidence::Medium);
        }
        $confidence = array_reduce($found, fn ($carry, $f) => $carry === null || $this->rank($f['confidence']) > $this->rank($carry) ? $f['confidence'] : $carry);
        $first = reset($found);
        $sample = fn (array $cluster) => implode(' · ', array_slice(array_column($cluster['points'], 'text'), 0, 3));
        $list = implode(', ', array_keys($found));

        return Detection::found(
            $confidence,
            '2 text columns on page'.(count($found) > 1 ? 's ' : ' ').$list,
            [$sample($first['left']), $sample($first['right'])],
            ['pages' => array_keys($found), 'sample_sides' => ['left', 'right']], // samples: left column, then right column (labelled per locale in the report)
        );
    }

    /** §4.1 tables in PDFs: ≥ 3 consecutive rows of ≥ 3 aligned cells. Low confidence by spec (the check reports unverified). */
    private function tables(array $pages): Detection
    {
        $c = config('ats.structure');
        foreach ($pages as $page) {
            $streak = [];
            $previous = null;
            foreach ($this->rows($page['lines']) as $row) {
                $xs = array_column($row['segments'], 'x');
                $aligned = 0;
                if ($previous !== null && count($xs) >= $c['table_cells'] && count($previous) >= $c['table_cells']) {
                    foreach ($xs as $x) {
                        foreach ($previous as $p) {
                            if (abs($x - $p) <= $c['table_align_pt']) {
                                $aligned++;
                                break;
                            }
                        }
                    }
                }
                if (count($xs) >= $c['table_cells'] && ($streak === [] || $aligned >= $c['table_cells'])) {
                    $streak[] = implode(' | ', array_column($row['segments'], 'text'));
                } else {
                    $streak = count($xs) >= $c['table_cells'] ? [implode(' | ', array_column($row['segments'], 'text'))] : [];
                }
                if (count($streak) >= $c['table_rows']) {
                    return Detection::found(Confidence::Low, "Aligned cells that look like a table on page {$page['number']}", $streak, ['pages' => [$page['number']]]);
                }
                $previous = $xs;
            }
        }

        return Detection::absent(Confidence::Medium);
    }

    /**
     * Header/footer text in a PDF = text in the top/bottom band that repeats on every page (digits
     * ignored, so "Page 1 of 2" counts). A one-page PDF has nothing to repeat: not detected (decision 2
     * of the S1 plan; a PDF has no separate header layer).
     */
    private function headerFooter(array $pages): Detection
    {
        if (count($pages) < 2) {
            return Detection::absent(Confidence::Medium, ['contact_only_there' => false], 'Single page: no repeated header or footer');
        }
        $band = (float) config('ats.structure.edge_band');
        $key = fn (string $t) => mb_strtolower(trim((string) preg_replace(['/\d+/', '/\s+/u'], ['#', ' '], $t)));
        $perPage = [];
        $edgeText = [];
        foreach ($pages as $page) {
            $keys = [];
            foreach ($page['lines'] as $line) {
                if ($line['y'] > $page['height'] * (1 - $band) || $line['y'] < $page['height'] * $band) {
                    $keys[$key($line['text'])] = $line['text'];
                }
            }
            $perPage[] = $keys;
        }
        $repeated = array_intersect_key(...$perPage);
        if ($repeated === []) {
            return Detection::absent(Confidence::Medium, ['contact_only_there' => false]);
        }
        $edge = implode(' ', $repeated);
        $edgeKeys = array_keys($repeated);
        $body = '';
        foreach ($pages as $page) {
            foreach ($page['lines'] as $line) {
                if (! in_array($key($line['text']), $edgeKeys, true)) {
                    $body .= ' '.$line['text'];
                }
            }
        }
        $inEdge = preg_match(self::EMAIL, $edge) || preg_match(self::PHONE, $edge);
        $inBody = preg_match(self::EMAIL, $body) || preg_match(self::PHONE, $body);
        $only = $inEdge && ! $inBody;

        return Detection::found(Confidence::Medium, $only ? 'Contact details appear only in a repeated page header or footer' : 'Repeated header or footer text', array_values($repeated), ['contact_only_there' => $only]);
    }

    /**
     * Images from `pdfimages -list`: placed size = pixels ÷ ppi (inches) × 72. Cropping is not visible,
     * so the area is an upper bound (medium confidence). Masks are not images. Nothing is extracted.
     */
    private function images(string $path, float $pageArea): Detection
    {
        $result = $this->poppler->exec('pdfimages', ['-list'], $path);
        $areas = [];
        foreach (array_slice(preg_split('/\R/', trim($result['output'])) ?: [], 2) as $row) {
            $cols = preg_split('/\s+/', trim($row));
            // page num type width height color comp bpc enc interp object ID x-ppi y-ppi size ratio
            if (count($cols) < 14 || $cols[2] !== 'image') {
                continue;
            }
            [$w, $h, $xppi, $yppi] = [(float) $cols[3], (float) $cols[4], (float) $cols[12], (float) $cols[13]];
            $areas[] = $xppi > 0 && $yppi > 0 ? ($w / $xppi * 72) * ($h / $yppi * 72) : null;
        }
        $known = array_filter($areas, fn ($a) => $a !== null);
        $largest = $known === [] ? null : round(min(100, 100 * max($known) / max(1.0, $pageArea)), 1);
        $extra = ['count' => count($areas), 'largest_area_pct' => $largest];

        return $areas === []
            ? Detection::absent(Confidence::High, $extra)
            : Detection::found(Confidence::Medium, count($areas).' image(s)'.($largest !== null ? ", largest about {$largest} % of the page" : ''), [], $extra);
    }

    private function rank(Confidence $c): int
    {
        return match ($c) {
            Confidence::Low => 0,
            Confidence::Medium => 1,
            Confidence::High => 2,
        };
    }
}
