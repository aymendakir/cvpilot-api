<?php

namespace App\Services\Ats\Parsing;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use ZipArchive;

/**
 * DOCX → ParsedDocument from the raw WordprocessingML (no PhpWord): body text in reading order
 * (tables and text boxes included, `mc:Fallback` copies skipped) and the §4.1 signals, all with high
 * confidence because they are read from the document structure, not guessed from positions.
 */
final class DocxParser implements DocumentParser
{
    private const NS = [
        'w' => 'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
        'wp' => 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing',
        'a' => 'http://schemas.openxmlformats.org/drawingml/2006/main',
        'v' => 'urn:schemas-microsoft-com:vml',
        'mc' => 'http://schemas.openxmlformats.org/markup-compatibility/2006',
        'wps' => 'http://schemas.microsoft.com/office/word/2010/wordprocessingShape',
    ];

    private const EMAIL = '/[\p{L}\p{N}._%+-]+@[\p{L}\p{N}.-]+\.\p{L}{2,}/u';

    private const PHONE = '/\+?\d[\d ().-]{7,}\d/';

    public function __construct(private readonly GlyphInspector $glyphs = new GlyphInspector) {}

    public function parse(string $path): ParsedDocument
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new UnreadableDocument(UnreadableDocument::CORRUPT);
        }
        try {
            $body = $this->xml($zip, 'word/document.xml');
            $parts = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (preg_match('#^word/(header|footer)\d*\.xml$#', $name)) {
                    $parts[$name] = $this->xml($zip, $name);
                }
            }
            $app = $zip->getFromName('docProps/app.xml');
        } finally {
            $zip->close();
        }

        $x = $this->xpath($body);
        foreach (iterator_to_array($x->query('//mc:Fallback')) as $fallback) {
            $fallback->parentNode?->removeChild($fallback);
        }

        $paragraphs = $this->paragraphs($x);
        $text = implode("\n", $paragraphs);
        $edgeText = '';
        foreach ($parts as $part) {
            $edgeText .= ' '.implode(' ', $this->paragraphs($this->xpath($part)));
        }
        $pages = is_string($app) && preg_match('#<Pages>(\d+)</Pages>#', $app, $m) && (int) $m[1] > 0 ? (int) $m[1] : null;
        $words = ParsedDocument::countWords($text);

        return new ParsedDocument(
            type: 'docx',
            text: $text,
            lines: array_map(fn (string $p) => new Line($p), $paragraphs),
            pages: $pages,
            wordCount: $words,
            textExtractable: $words > 0,
            structure: new Structure(
                inspected: true,
                columns: $this->columns($x),
                tables: $this->tables($x),
                images: $this->images($x),
                textBoxes: $this->textBoxes($x),
                headerFooter: $this->headerFooter(trim($edgeText), $text),
                glyphIssues: $this->glyphs->inspect($paragraphs),
            ),
            sizeBytes: filesize($path) ?: null,
        );
    }

    private function xml(ZipArchive $zip, string $name): DOMDocument
    {
        $xml = $zip->getFromName($name);
        $doc = new DOMDocument;
        // LIBXML_NONET: never fetch anything; no entity expansion (XXE) since LIBXML_NOENT is not set.
        if (! is_string($xml) || ! @$doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw new UnreadableDocument(UnreadableDocument::CORRUPT);
        }

        return $doc;
    }

    private function xpath(DOMDocument $doc): DOMXPath
    {
        $x = new DOMXPath($doc);
        foreach (self::NS as $prefix => $uri) {
            $x->registerNamespace($prefix, $uri);
        }

        return $x;
    }

    /**
     * Paragraph texts in document order. A paragraph inside a text box is its own paragraph (it is not
     * glued to the paragraph that anchors the box).
     *
     * @return list<string>
     */
    private function paragraphs(DOMXPath $x): array
    {
        $out = [];
        $root = $x->document->documentElement;
        if ($root !== null) {
            $this->walk($root, $out, null);
        }

        return array_values(array_filter(array_map(fn ($p) => trim((string) preg_replace('/[ \t]+/u', ' ', $p)), $out), fn ($p) => $p !== ''));
    }

    /** @param  list<string>  $out */
    private function walk(DOMNode $node, array &$out, ?int $current): void
    {
        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            $w = $child->namespaceURI === self::NS['w'];
            if ($w && $child->localName === 'p') {
                $out[] = '';
                $index = array_key_last($out);
                $this->walk($child, $out, $index);
            } elseif ($w && $child->localName === 't' && $current !== null) {
                $out[$current] .= $child->textContent;
            } elseif ($w && in_array($child->localName, ['tab', 'br', 'cr'], true) && $current !== null) {
                $out[$current] .= ' ';
            } elseif ($w && $child->localName === 'sym' && $current !== null) {
                // <w:sym w:font="Wingdings" w:char="F0B7"/>: a symbol-font glyph, kept as its private-use code point.
                $out[$current] .= mb_chr((int) hexdec((string) $child->getAttributeNS(self::NS['w'], 'char')) ?: 0xFFFD);
            } else {
                $this->walk($child, $out, $current);
            }
        }
    }

    private function columns(DOMXPath $x): Detection
    {
        $max = 1;
        foreach ($x->query('//w:sectPr/w:cols') as $cols) {
            /** @var DOMElement $cols */
            $num = (int) $cols->getAttributeNS(self::NS['w'], 'num');
            $max = max($max, $num, $x->query('w:col', $cols)->length);
        }

        return $max > 1
            ? Detection::found(Confidence::High, "{$max} text columns in the page layout")
            : Detection::absent();
    }

    private function tables(DOMXPath $x): Detection
    {
        $samples = [];
        $count = 0;
        foreach ($x->query('//w:tbl[not(ancestor::w:tbl)]') as $table) {
            $text = trim((string) preg_replace('/\s+/u', ' ', $table->textContent));
            if ($text !== '') {
                $count++;
                $samples[] = $text;
            }
        }

        return $count > 0
            ? Detection::found(Confidence::High, "{$count} table(s) containing text", $samples, ['count' => $count])
            : Detection::absent(Confidence::High, ['count' => 0]);
    }

    private function images(DOMXPath $x): Detection
    {
        $page = $x->query('//w:sectPr/w:pgSz')->item(0);
        // Page size in points; twips / 20. Default A4 when the section has no size.
        $pageW = $page instanceof DOMElement ? (int) $page->getAttributeNS(self::NS['w'], 'w') / 20 : 595.3;
        $pageH = $page instanceof DOMElement ? (int) $page->getAttributeNS(self::NS['w'], 'h') / 20 : 841.9;
        $pageArea = max(1.0, $pageW * $pageH);

        $areas = [];
        foreach ($x->query('//w:drawing[.//a:blip]') as $drawing) {
            $extent = $x->query('.//wp:extent', $drawing)->item(0);
            $areas[] = $extent instanceof DOMElement
                ? ((int) $extent->getAttribute('cx') / 12700) * ((int) $extent->getAttribute('cy') / 12700)
                : null;
        }
        foreach ($x->query('//w:pict[.//v:imagedata]') as $pict) {
            $shape = $x->query('.//v:shape', $pict)->item(0);
            $style = $shape instanceof DOMElement ? $shape->getAttribute('style') : '';
            $areas[] = $this->vmlLength($style, 'width') !== null && $this->vmlLength($style, 'height') !== null
                ? $this->vmlLength($style, 'width') * $this->vmlLength($style, 'height')
                : null;
        }

        $known = array_filter($areas, fn ($a) => $a !== null);
        $largest = $known === [] ? null : round(100 * max($known) / $pageArea, 1);
        $extra = ['count' => count($areas), 'largest_area_pct' => $largest];

        return $areas === []
            ? Detection::absent(Confidence::High, $extra)
            : Detection::found(Confidence::High, count($areas).' image(s)'.($largest !== null ? ", largest {$largest} % of the page" : ''), [], $extra);
    }

    /** A VML style length in points ("width:113pt", "2in", "4cm", "50mm", "96px"). */
    private function vmlLength(string $style, string $property): ?float
    {
        if (! preg_match('/(?:^|;)\s*'.$property.'\s*:\s*([\d.]+)\s*(pt|in|cm|mm|px)?/i', $style, $m)) {
            return null;
        }

        return (float) $m[1] * match (strtolower($m[2] ?? 'pt')) {
            'in' => 72, 'cm' => 72 / 2.54, 'mm' => 72 / 25.4, 'px' => 0.75, default => 1,
        };
    }

    /** Every text box (DrawingML `wps:txbx` or VML `v:textbox`) holds its text in one `w:txbxContent`. */
    private function textBoxes(DOMXPath $x): Detection
    {
        $samples = [];
        foreach ($x->query('//w:txbxContent[not(ancestor::w:txbxContent)]') as $box) {
            $text = trim((string) preg_replace('/\s+/u', ' ', $box->textContent));
            if ($text !== '') {
                $samples[] = $text;
            }
        }

        return $samples !== []
            ? Detection::found(Confidence::High, count($samples).' text box(es) with content', $samples, ['count' => count($samples)])
            : Detection::absent(Confidence::High, ['count' => 0]);
    }

    private function headerFooter(string $edgeText, string $bodyText): Detection
    {
        $inEdge = preg_match(self::EMAIL, $edgeText) || preg_match(self::PHONE, $edgeText);
        $inBody = preg_match(self::EMAIL, $bodyText) || preg_match(self::PHONE, $bodyText);
        $extra = ['contact_only_there' => $inEdge && ! $inBody];

        return $edgeText === ''
            ? Detection::absent(Confidence::High, $extra)
            : Detection::found(Confidence::High, $extra['contact_only_there'] ? 'Contact details appear only in the page header or footer' : 'Text in the page header or footer', [$edgeText], $extra);
    }
}
