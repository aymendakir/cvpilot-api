<?php

/*
 * Phase 3 S0 spike (SPEC-ats.md §12 R1). THROWAWAY: deleted in S1 when the heuristics that hold up
 * move into app/Services/Ats/Parsing with tests. Not loaded by PHPUnit.
 *
 *   php tests/fixtures/ats/spike/probe.php file.pdf [more.pdf …]     human-readable report
 *   php tests/fixtures/ats/spike/probe.php --json file.pdf           one JSON object per file
 *   php tests/fixtures/ats/spike/probe.php --dump file.pdf           every text run with x/y/size
 *   php tests/fixtures/ats/spike/probe.php --poppler file.pdf        same heuristics on pdftotext -bbox-layout
 *
 * Question it answers: does smalot/pdfparser give enough position data to detect the §4.1 signals
 * (columns, tables, header/footer text, images, glyph issues), and how fast?
 */

require __DIR__.'/../../../../vendor/autoload.php';

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

const SPIKE_EDGE = 0.07;         // header/footer band: top/bottom 7 % of the page (§4.1)
const SPIKE_MIN_LINES = 8;       // a column needs at least 8 line starts (§4.1)
const SPIKE_COLUMN_GAP = 0.25;   // columns at least 25 % of the page width apart (§4.1)
const SPIKE_OVERLAP = 0.5;       // and overlapping vertically by at least 50 % (§4.1)

/**
 * Text runs of a page: [x, y, size, width estimate, text]. smalot returns the text matrix (Tm) of each
 * run; its fontSize is the Tf size, and the effective size is Tf × the matrix scale.
 *
 * @return list<array{x: float, y: float, size: float, w: float, text: string}>
 */
function spike_runs($page): array
{
    $runs = [];
    foreach ($page->getDataTm() as $data) {
        [$m, $text] = $data;
        $text = (string) $text;
        if (trim($text) === '') {
            continue;
        }
        $scale = abs((float) $m[3]) ?: abs((float) $m[0]) ?: 1.0;
        $size = max(1.0, (float) ($data[3] ?? 10) * $scale);
        $runs[] = [
            'x' => (float) $m[4],
            'y' => (float) $m[5],
            'size' => round($size, 1),
            'w' => mb_strlen($text) * $size * 0.5, // average glyph width ≈ half the font size
            'text' => $text,
        ];
    }

    return $runs;
}

/** @return array{0: float, 1: float} page width and height in points */
function spike_page_size($page): array
{
    $box = $page->getDetails()['MediaBox'] ?? [0, 0, 595.28, 841.89];

    return [(float) $box[2] - (float) $box[0], (float) $box[3] - (float) $box[1]];
}

/**
 * Rows (same baseline ±2 pt) split into segments: a new segment starts when a run begins far to the
 * right of where the previous one ended. A segment start is a "line start" for column detection.
 *
 * @return list<array{y: float, segments: list<array{x: float, end: float, text: string}>}>
 */
function spike_rows(array $runs): array
{
    usort($runs, fn ($a, $b) => [-$a['y'], $a['x']] <=> [-$b['y'], $b['x']]);
    $rows = [];
    foreach ($runs as $run) {
        $last = array_key_last($rows);
        if ($last !== null && abs($rows[$last]['y'] - $run['y']) <= 2) {
            $rows[$last]['runs'][] = $run;
        } else {
            $rows[] = ['y' => $run['y'], 'runs' => [$run]];
        }
    }

    foreach ($rows as &$row) {
        usort($row['runs'], fn ($a, $b) => $a['x'] <=> $b['x']);
        $segments = [];
        foreach ($row['runs'] as $run) {
            $s = array_key_last($segments);
            $gap = max(3 * $run['size'], 15);
            if ($s !== null && $run['x'] <= $segments[$s]['end'] + $gap) {
                $segments[$s]['end'] = max($segments[$s]['end'], $run['x'] + $run['w']);
                $segments[$s]['text'] .= ' '.$run['text'];
            } else {
                $segments[] = ['x' => $run['x'], 'end' => $run['x'] + $run['w'], 'text' => $run['text']];
            }
        }
        $row = ['y' => $row['y'], 'segments' => $segments];
    }

    return $rows;
}

/** §4.1 columns: clusters of line-start x, ≥ 8 lines each, ≥ 25 % width apart, overlapping ≥ 50 %. */
function spike_columns(array $rows, float $width): array
{
    $starts = [];
    foreach ($rows as $row) {
        foreach ($row['segments'] as $segment) {
            $starts[] = ['x' => $segment['x'], 'y' => $row['y']];
        }
    }
    usort($starts, fn ($a, $b) => $a['x'] <=> $b['x']);

    $clusters = [];
    foreach ($starts as $start) {
        $c = array_key_last($clusters);
        if ($c !== null && $start['x'] - $clusters[$c]['last'] <= 12) {
            $clusters[$c]['points'][] = $start;
            $clusters[$c]['last'] = $start['x'];
        } else {
            $clusters[] = ['first' => $start['x'], 'last' => $start['x'], 'points' => [$start]];
        }
    }
    $big = array_values(array_filter($clusters, fn ($c) => count($c['points']) >= SPIKE_MIN_LINES));

    $best = null;
    foreach ($big as $i => $a) {
        foreach (array_slice($big, $i + 1) as $b) {
            $gap = $b['first'] - $a['first'];
            if ($gap < SPIKE_COLUMN_GAP * $width) {
                continue;
            }
            $ya = array_column($a['points'], 'y');
            $yb = array_column($b['points'], 'y');
            $overlap = max(0, min(max($ya), max($yb)) - max(min($ya), min($yb)));
            $shorter = min(max($ya) - min($ya), max($yb) - min($yb)) ?: 1;
            $ratio = $overlap / $shorter;
            if ($ratio >= SPIKE_OVERLAP && ($best === null || count($a['points']) + count($b['points']) > $best['lines'])) {
                $best = [
                    'left_x' => round($a['first']), 'right_x' => round($b['first']),
                    'left_lines' => count($a['points']), 'right_lines' => count($b['points']),
                    'gap_pct' => round(100 * $gap / $width), 'overlap_pct' => round(100 * $ratio),
                    'lines' => count($a['points']) + count($b['points']),
                ];
            }
        }
    }

    return ['detected' => $best !== null, 'detail' => $best, 'clusters' => count($big)];
}

/** §4.1 tables: ≥ 3 consecutive rows with ≥ 3 cells whose starts line up (±6 pt) with the previous row. */
function spike_tables(array $rows): array
{
    $run = 0;
    $best = 0;
    $previous = null;
    foreach ($rows as $row) {
        $xs = array_column($row['segments'], 'x');
        if (count($xs) >= 3 && $previous !== null && count($previous) >= 3) {
            $aligned = 0;
            foreach ($xs as $x) {
                foreach ($previous as $p) {
                    if (abs($x - $p) <= 6) {
                        $aligned++;
                        break;
                    }
                }
            }
            $run = $aligned >= 3 ? max($run, 1) + 1 : (count($xs) >= 3 ? 1 : 0);
        } else {
            $run = count($xs) >= 3 ? 1 : 0;
        }
        $best = max($best, $run);
        $previous = $xs;
    }

    return ['detected' => $best >= 3, 'longest_aligned_rows' => $best];
}

/** Text in the top/bottom 7 % band, and whether the email/phone only appear there. */
function spike_edges(array $runs, float $height, string $all): array
{
    $edge = array_filter($runs, fn ($r) => $r['y'] > $height * (1 - SPIKE_EDGE) || $r['y'] < $height * SPIKE_EDGE);
    $edgeText = implode(' ', array_column($edge, 'text'));
    $bodyText = implode(' ', array_column(array_filter($runs, fn ($r) => ! in_array($r, $edge, true)), 'text'));
    $email = '/[\w.+-]+@[\w-]+\.[\w.]+/u';

    return [
        'edge_runs' => count($edge),
        'email_only_in_edges' => preg_match($email, $edgeText) === 1 && preg_match($email, $bodyText) !== 1,
    ];
}

/** Image XObjects of a page, with the placed size when the content stream shows a simple `w 0 0 h x y cm /Name Do`. */
function spike_images($page, float $width, float $height): array
{
    $images = [];
    foreach ($page->getXObjects() as $name => $object) {
        // smalot lists each XObject twice: by resource name and by index.
        if (is_int($name) || ($object->getDetails()['Subtype'] ?? null) !== 'Image') {
            continue;
        }
        $images[$name] = ['px' => ($object->getDetails()['Width'] ?? '?').'×'.($object->getDetails()['Height'] ?? '?'), 'area_pct' => null];
    }
    // Page::getContent() came back empty on these files; read the Contents stream(s) directly.
    $content = '';
    try {
        $contents = $page->get('Contents');
        foreach (method_exists($contents, 'getContent') ? [$contents] : $contents->getContent() as $stream) {
            $content .= $stream->getContent()."\n";
        }
    } catch (Throwable) {
    }
    if (preg_match_all('#([\d.]+)\s+0\s+0\s+([\d.]+)\s+[\d.-]+\s+[\d.-]+\s+cm\s*/(\w+)\s+Do#', $content, $m, PREG_SET_ORDER)) {
        foreach ($m as [, $w, $h, $name]) {
            if (isset($images[$name])) {
                $images[$name]['area_pct'] = round(100 * (float) $w * (float) $h / ($width * $height), 1);
            }
        }
    }

    return array_values($images);
}

function spike_glyphs(string $text): array
{
    return [
        'private_use' => preg_match_all('/\p{Co}/u', $text),
        'replacement' => substr_count($text, "\u{FFFD}"),
        'letter_spaced' => preg_match_all('/(?:\b\p{L} ){4,}\p{L}\b/u', $text),
    ];
}

function spike_analyse(string $file, bool $dump = false): array
{
    $started = hrtime(true);
    $config = new Config;
    $config->setDataTmFontInfoHasToBeIncluded(true);
    $result = ['file' => basename($file), 'pages' => []];

    try {
        $pdf = (new Parser([], $config))->parseFile($file);
    } catch (Throwable $e) {
        return $result + ['error' => get_class($e).': '.$e->getMessage(), 'ms' => (int) ((hrtime(true) - $started) / 1e6)];
    }
    $text = $pdf->getText();
    $result['producer'] = $pdf->getDetails()['Producer'] ?? null;
    $result['words'] = count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY));
    $result['glyphs'] = spike_glyphs($text);

    foreach ($pdf->getPages() as $i => $page) {
        [$width, $height] = spike_page_size($page);
        try {
            $runs = spike_runs($page);
        } catch (Throwable $e) {
            $result['pages'][] = ['page' => $i + 1, 'error' => $e->getMessage()];

            continue;
        }
        if ($dump) {
            foreach ($runs as $r) {
                printf("p%d x=%6.1f y=%6.1f size=%4.1f %s\n", $i + 1, $r['x'], $r['y'], $r['size'], $r['text']);
            }
        }
        $rows = spike_rows($runs);
        $inside = count(array_filter($runs, fn ($r) => $r['x'] >= 0 && $r['x'] <= $width && $r['y'] >= 0 && $r['y'] <= $height));
        $result['pages'][] = [
            'page' => $i + 1,
            'size' => round($width).'×'.round($height),
            'runs' => count($runs),
            'runs_inside_page' => $inside,
            'rows' => count($rows),
            'columns' => spike_columns($rows, $width),
            'tables' => spike_tables($rows),
            'edges' => spike_edges($runs, $height, $text),
            'images' => spike_images($page, $width, $height),
        ];
    }
    $result['ms'] = (int) ((hrtime(true) - $started) / 1e6);

    return $result;
}

/**
 * Comparison only: the same heuristics on poppler's `pdftotext -bbox-layout` output (line boxes, top-down
 * y). Poppler is NOT in the Docker image (SPEC-ats.md §17 answer 3: ask before adding it); this mode
 * runs only where the binary exists, to show what a poppler-based parser would see.
 */
function spike_poppler(string $file): array
{
    $started = hrtime(true);
    $html = shell_exec('pdftotext -bbox-layout '.escapeshellarg($file).' - 2>/dev/null');
    if (! is_string($html) || $html === '') {
        return ['file' => basename($file), 'error' => 'pdftotext not available or failed'];
    }
    $doc = new DOMDocument;
    @$doc->loadHTML($html);
    $result = ['file' => basename($file), 'engine' => 'poppler', 'pages' => []];
    foreach ($doc->getElementsByTagName('page') as $n => $page) {
        $width = (float) $page->getAttribute('width');
        $height = (float) $page->getAttribute('height');
        $rows = [];
        $runs = [];
        foreach ($page->getElementsByTagName('line') as $line) {
            $words = [];
            foreach ($line->getElementsByTagName('word') as $word) {
                $words[] = $word->textContent;
            }
            $x = (float) $line->getAttribute('xmin');
            $y = $height - (float) $line->getAttribute('ymax'); // bottom-up, like PDF space
            $size = (float) $line->getAttribute('ymax') - (float) $line->getAttribute('ymin');
            $runs[] = ['x' => $x, 'y' => $y, 'size' => $size, 'w' => (float) $line->getAttribute('xmax') - $x, 'text' => implode(' ', $words)];
        }
        $rows = spike_rows($runs);
        $result['pages'][] = [
            'page' => $n + 1,
            'size' => round($width).'×'.round($height),
            'runs' => count($runs),
            'runs_inside_page' => count($runs),
            'rows' => count($rows),
            'columns' => spike_columns($rows, $width),
            'tables' => spike_tables($rows),
            'edges' => spike_edges($runs, $height, ''),
            'images' => [],
        ];
    }
    $result['ms'] = (int) ((hrtime(true) - $started) / 1e6);

    return $result;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $args = array_slice($argv, 1);
    $json = in_array('--json', $args, true);
    $dump = in_array('--dump', $args, true);
    $poppler = in_array('--poppler', $args, true);
    foreach (array_filter($args, fn ($a) => ! str_starts_with($a, '--')) as $file) {
        $r = $poppler ? spike_poppler($file) : spike_analyse($file, $dump);
        if ($json) {
            echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";

            continue;
        }
        printf("== %s (%s) words=%s ms=%d %s\n", $r['file'], $r['producer'] ?? '-', $r['words'] ?? '-', $r['ms'], isset($r['error']) ? 'ERROR '.$r['error'] : '');
        foreach ($r['pages'] as $p) {
            if (isset($p['error'])) {
                printf("  p%d ERROR %s\n", $p['page'], $p['error']);

                continue;
            }
            $c = $p['columns'];
            printf(
                "  p%d %s runs=%d (inside %d) rows=%d | columns=%s %s | tables=%s (rows %d) | edge runs=%d email_only_edge=%s | images=%s\n",
                $p['page'], $p['size'], $p['runs'], $p['runs_inside_page'], $p['rows'],
                $c['detected'] ? 'YES' : 'no', $c['detail'] ? json_encode($c['detail']) : "(clusters≥8: {$c['clusters']})",
                $p['tables']['detected'] ? 'YES' : 'no', $p['tables']['longest_aligned_rows'],
                $p['edges']['edge_runs'], $p['edges']['email_only_in_edges'] ? 'yes' : 'no',
                json_encode($p['images']),
            );
        }
        if (isset($r['glyphs'])) {
            printf("  glyphs %s\n", json_encode($r['glyphs']));
        }
    }
}
