<?php

namespace App\Services\Ats\Sections;

use App\Services\Ats\Language\Normalizer;

/**
 * Recognises section headings (EN/FR, resources/ats/headings.{en,fr}.json) and assigns every line to a
 * section. A heading is a short line (≤ 5 words, ≤ 60 characters, no final full stop) that, folded,
 * equals a phrase of a section, optionally with one of the section's prefixes and suffixes.
 */
final class SectionDetector
{
    /** @var array<string, array{phrases: list<string>, prefixes: list<string>, suffixes: list<string>}>|null */
    private ?array $vocabulary = null;

    public function __construct(private readonly Normalizer $normalizer = new Normalizer) {}

    /** @param  list<string>  $lines */
    public function detect(array $lines): Sections
    {
        $lines = array_values($lines);
        $current = 'header';
        $sectionOf = [];
        $headings = [];
        $headingLines = [];
        foreach ($lines as $i => $line) {
            $kind = $this->headingKind($line);
            if ($kind !== null) {
                $current = $kind;
                $headings[$kind] ??= ['text' => trim($line), 'line' => $i + 1];
                $headingLines[] = $i;
            }
            $sectionOf[$i] = $current;
        }

        return new Sections($lines, $sectionOf, $headings, $headingLines);
    }

    /** The section a line opens, or null when it is not a heading. */
    public function headingKind(string $line): ?string
    {
        $key = $this->key($line);
        if ($key === null) {
            return null;
        }
        foreach ($this->vocabulary() as $kind => $v) {
            foreach ($this->variants($key, $v['prefixes'], $v['suffixes']) as $candidate) {
                if (in_array($candidate, $v['phrases'], true)) {
                    return $kind;
                }
            }
        }

        return null;
    }

    /** Folded comparison key, or null when the line cannot be a heading. */
    private function key(string $line): ?string
    {
        // Letter-spaced headings ("S K I L L S", "E X P É R I E N C E  P R O"): drop single spaces and keep
        // the wider word gaps. Done before display(), which would collapse those gaps.
        $text = trim(str_replace(["\u{00A0}", "\u{202F}"], ' ', $line));
        if (preg_match('/^(?:\p{L} ){2,}\p{L}(?:\s{2,}(?:\p{L} )*\p{L})*$/u', $text)) {
            $text = (string) preg_replace(['/(?<=\p{L}) (?=\p{L})/u', '/\s{2,}/u'], ['', ' '], $text);
        }
        $text = trim($this->normalizer->display($text));
        if ($text === '' || mb_strlen($text) > 60 || preg_match('/\.$/u', $text)) {
            return null;
        }
        $text = $this->normalizer->fold($text);
        // Numbering, icons and decoration before; colon, dashes and pipes after.
        $text = (string) preg_replace(['/^[^\p{L}]+/u', '/[\s:\-|–—•.]+$/u'], '', $text);
        $text = str_replace(['&', '+', '’', "'"], [' and ', ' and ', "'", "'"], $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '' || count(explode(' ', $text)) > 5) {
            return null;
        }

        return $text;
    }

    /** @return list<string> */
    private function variants(string $key, array $prefixes, array $suffixes): array
    {
        $out = [$key];
        foreach ($prefixes as $p) {
            if (str_starts_with($key, $p.' ')) {
                $out[] = substr($key, strlen($p) + 1);
            }
        }
        foreach ($out as $base) {
            foreach ($suffixes as $s) {
                if (str_ends_with($base, ' '.$s)) {
                    $out[] = substr($base, 0, -strlen($s) - 1);
                }
            }
        }

        // French "et" joins like English "and": "stages et experiences" is listed as a phrase.
        return array_values(array_unique($out));
    }

    /** @return array<string, array{phrases: list<string>, prefixes: list<string>, suffixes: list<string>}> */
    private function vocabulary(): array
    {
        if ($this->vocabulary !== null) {
            return $this->vocabulary;
        }
        $merged = array_fill_keys(['experience', 'education', 'skills', 'other'], ['phrases' => [], 'prefixes' => [], 'suffixes' => []]);
        foreach (['en', 'fr'] as $language) {
            $data = json_decode((string) file_get_contents(resource_path("ats/headings.{$language}.json")), true, flags: JSON_THROW_ON_ERROR);
            foreach (['experience', 'education', 'skills', 'other'] as $kind) {
                foreach (['phrases', 'prefixes', 'suffixes'] as $part) {
                    foreach ($data[$kind][$part] as $value) {
                        $merged[$kind][$part][] = $this->normalizer->fold($value);
                    }
                }
            }
        }

        return $this->vocabulary = $merged;
    }
}
