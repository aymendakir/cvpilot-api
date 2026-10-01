<?php

namespace App\Services\Ats\Keywords;

use App\Services\Ats\Language\Normalizer;
use App\Services\Ats\Language\StopWords;
use App\Services\Ats\Sections\Sections;

/**
 * Keywords of a job description (SPEC-ats.md §6.1 as built in S2, decision 1, rule (b′)):
 *
 * - A short line naming requirements ("Requirements:", "Profil recherché :") opens a required block, one
 *   naming extras ("Nice to have:", "Atout :") a preferred block. Any other short line ending with ":"
 *   closes the block, and so does a line without a list marker after a blank line once the block has
 *   items ("What we offer").
 * - Inside a block, each list item (a line, or a part of it split on commas, semicolons, "and", "et",
 *   "&") of ≤ 4 words is one keyword as written, after dropping filler ("Experience with", "Maîtrise de",
 *   "… experience"). An item that contains taxonomy phrases but is not one itself gives those phrases
 *   instead. Longer items give their taxonomy phrases only.
 * - Outside blocks, only taxonomy phrases (skills with anywhere=true, not guarded) are taken; they are
 *   required when they occur ≥ 2 times, preferred otherwise.
 * - One keyword per taxonomy group; block wording and kind win; generic words are dropped; at most 40.
 */
final class KeywordExtractor
{
    public const MAX_TERMS = 40;

    private const MAX_ITEM_WORDS = 4;

    private const HEADING_MAX_WORDS = 6;

    /** Folded heading words → block kind. Preferred is tested first ("Preferred qualifications"). */
    private const PREFERRED = 'nice[ -]to[ -]haves?|preferred|bonus|pluses|a plus|un plus|souhaite(?:e|s|es)?|souhaitable|atouts?|apprecie(?:e|s|es)?|optional|optionnel(?:le)?s?|desirable';

    private const REQUIRED = 'requirements?|qualifications?|must[ -]haves?|required|what you(?:\'ll| will) need|what we(?:\'re| are) looking for|you have|your profile|profil recherche|votre profil|competences requises|requis(?:es)?|exigences|vous avez|prerequis|skills|competences';

    /** Words around a heading written without a colon ("Minimum qualifications", "Compétences techniques"). */
    private const BEFORE = '(?:(?:minimum|basic|key|core|technical|additional|preferred|required|our|your|the)\s+)?';

    private const AFTER = '(?:\s+(?:techniques?|cles?|requises?|recherchees?|souhaitees?))?';

    /** Leading filler of a list item ("Strong experience with", "Bonne maîtrise de"). */
    private const FILLER = '/^(?:(?:strong|solid|good|very good|excellent|proven|deep|hands[- ]on|working|basic|advanced|bonne|bonnes|solide|solides|excellente?s?|tres bonne)\s+)*(?:(?:experience|expérience|knowledge|connaissance|connaissances|proficiency|familiarity|expertise|maîtrise|maitrise|understanding|compréhension|skills|compétences)\s+(?:with|in|of|using|en|de|du|des|d\'|avec|sur)\s*)?/iu';

    private const SUFFIX = '/\s+(?:experience|skills|knowledge)$/iu';

    private const YEARS = '/\d+\s*\+?\s*(?:years?|yrs|ans|années?)\b/iu';

    /** @var array<string, true>|null */
    private ?array $blocklist = null;

    public function __construct(
        private readonly Taxonomy $taxonomy = new Taxonomy,
        private readonly Normalizer $normalizer = new Normalizer,
        private readonly StopWords $stopWords = new StopWords,
    ) {}

    /** @return list<JobKeyword> in job-description order */
    public function extract(string $jobDescription): array
    {
        $lines = array_map(fn ($l) => $this->normalizer->display($l), preg_split('/\R/u', $jobDescription));

        /** @var array<string, array{term: string, kind: ?string, group: ?int, key: string, context: string, order: int}> $found */
        $found = [];
        $order = 0;
        $block = null;
        $blockItems = 0;
        $afterBlank = false;
        foreach ($lines as $line) {
            [$heading, $rest] = $this->heading($line);
            if ($heading !== false) {
                [$block, $blockItems, $line] = [$heading, 0, $rest];
            } elseif ($block !== null && $afterBlank && $blockItems > 0 && ! preg_match(Sections::MARKER, trim($line))) {
                $block = null; // a paragraph after the list: "What we offer", company text
            }
            $afterBlank = trim($line) === '';
            if ($afterBlank) {
                continue;
            }
            $blockItems++;
            $candidates = $block === null ? $this->hits($line, $this->taxonomy->scannable()) : $this->items($line);
            foreach ($candidates as [$term, $group]) {
                $this->add($found, $term, $group, $block, $line, $order++);
            }
        }

        $text = implode("\n", $lines);
        $keywords = [];
        foreach ($found as $candidate) {
            $frequency = $this->frequency($text, $candidate);
            $kind = $candidate['kind'] ?? ($frequency >= 2 ? 'required' : 'preferred');
            $keywords[] = ['keyword' => new JobKeyword($candidate['term'], $kind, $candidate['group'], $frequency, $this->clip($candidate['context'])), 'order' => $candidate['order']];
        }
        // At most 40: by weight, then frequency, then taxonomy terms first; then back to job-description order.
        usort($keywords, fn ($a, $b) => [$b['keyword']->weight(), $b['keyword']->frequency, $b['keyword']->group !== null, $a['order']]
            <=> [$a['keyword']->weight(), $a['keyword']->frequency, $a['keyword']->group !== null, $b['order']]);
        $keywords = array_slice($keywords, 0, self::MAX_TERMS);
        usort($keywords, fn ($a, $b) => $a['order'] <=> $b['order']);

        return array_column($keywords, 'keyword');
    }

    /**
     * A block heading: `[kind, rest of line]` (kind null for a heading that closes the block), or
     * `[false, line]` when the line is not a heading.
     *
     * @return array{0: 'required'|'preferred'|null|false, 1: string}
     */
    private function heading(string $line): array
    {
        $trimmed = trim((string) preg_replace('/^[#*\s]+|[*\s]+$/u', '', $line));
        $colon = mb_strpos($trimmed, ':');
        $label = $colon === false ? $trimmed : trim(mb_substr($trimmed, 0, $colon));
        $rest = $colon === false ? '' : trim(mb_substr($trimmed, $colon + 1));
        $words = count(preg_split('/\s+/u', $label, -1, PREG_SPLIT_NO_EMPTY));
        if ($label === '' || $words > self::HEADING_MAX_WORDS || preg_match('/[.!?]$/u', $label)) {
            return [false, $line];
        }
        // With a colon the label only has to name a block ("Compétences techniques :"); without one the
        // whole line must be a heading phrase, so a list item such as "Communication skills" stays an item.
        $folded = $this->normalizer->fold($label);
        foreach (['preferred' => self::PREFERRED, 'required' => self::REQUIRED] as $kind => $words) {
            $pattern = $colon !== false ? "/\\b(?:{$words})\\b/u" : '/^'.self::BEFORE."(?:{$words})".self::AFTER.'$/u';
            if (preg_match($pattern, $folded)) {
                return [$kind, $rest];
            }
        }

        return $colon !== false && $rest === '' ? [null, ''] : [false, $line];
    }

    /**
     * Candidates of one line inside a block (rule (b′)).
     *
     * @return list<array{0: string, 1: ?int}>
     */
    private function items(string $line): array
    {
        $line = trim((string) preg_replace(Sections::MARKER, '', trim($line)));
        // Text in parentheses is a list of its own: "Cloud (AWS, GCP)".
        preg_match_all('/\(([^()]*)\)/u', $line, $m);
        $parts = [trim((string) preg_replace('/\s*\([^()]*\)/u', '', $line)), ...$m[1]];

        $out = [];
        foreach ($parts as $part) {
            foreach (preg_split('/\s*[,;•·|]\s*/u', $part, -1, PREG_SPLIT_NO_EMPTY) as $piece) {
                $pieces = $this->taxonomy->groupOf($piece) !== null ? [$piece] : preg_split('/\s+(?:and|et|&)\s+/iu', $piece);
                foreach ($pieces as $item) {
                    array_push($out, ...$this->item($item));
                }
            }
        }

        return $out;
    }

    /** @return list<array{0: string, 1: ?int}> */
    private function item(string $item): array
    {
        $item = trim($item, " \t.:!?\"'");
        $item = trim((string) preg_replace([self::FILLER, self::SUFFIX], '', $item));
        if ($item === '') {
            return [];
        }
        $words = count(preg_split('/\s+/u', $item, -1, PREG_SPLIT_NO_EMPTY));
        $group = $this->taxonomy->groupOf($item);
        if ($words <= self::MAX_ITEM_WORDS && $group !== null) {
            return [[$item, $group]];
        }
        $hits = $this->hits($item, $this->taxonomy->all());
        if ($hits !== [] || $words > self::MAX_ITEM_WORDS) {
            return $hits;
        }

        return $this->generic($item) ? [] : [[$item, null]];
    }

    /**
     * Taxonomy phrases in a text, as written there, longest phrase first, no overlaps.
     *
     * @param  list<array{key: string, group: int}>  $phrases
     * @return list<array{0: string, 1: int}>
     */
    private function hits(string $text, array $phrases): array
    {
        $tokens = Tokens::of($text);
        $taken = [];
        $hits = [];
        foreach ($phrases as $phrase) {
            $keys = explode(' ', $phrase['key']);
            foreach (Tokens::find($tokens, $keys, $this->taxonomy->guarded($phrase['key'])) as $start) {
                $span = range($start, $start + count($keys) - 1);
                if (array_intersect($span, $taken) !== []) {
                    continue;
                }
                array_push($taken, ...$span);
                $end = $tokens[$start + count($keys) - 1]['end'];
                $hits[$start] = [substr($text, $tokens[$start]['start'], $end - $tokens[$start]['start']), $phrase['group']];
            }
        }
        ksort($hits);

        return array_values($hits);
    }

    /** A list item that is not a keyword: a generic word, stop words only, a number of years, no letters. */
    private function generic(string $item): bool
    {
        if (preg_match(self::YEARS, $item) || ! preg_match('/\p{L}/u', $item)) {
            return true;
        }
        $key = Tokens::key($item);
        if (isset($this->blocklist()[$key])) {
            return true;
        }
        foreach (explode(' ', $key) as $token) {
            if (! isset($this->blocklist()[$token]) && ! $this->stopWords->contains($token, 'en') && ! $this->stopWords->contains($token, 'fr')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, array{term: string, kind: ?string, group: ?int, key: string, context: string, order: int}>  $found
     * @param  'required'|'preferred'|null  $block
     */
    private function add(array &$found, string $term, ?int $group, ?string $block, string $line, int $order): void
    {
        $key = Tokens::key($term);
        $id = $group !== null ? "group:{$group}" : "term:{$key}";
        $existing = $found[$id] ?? null;
        if ($existing === null || ($existing['kind'] === null && $block !== null)) {
            // New, or a block item replacing a free-text hit (block wording and kind win).
            $found[$id] = ['term' => $term, 'kind' => $block, 'group' => $group, 'key' => $key, 'context' => trim($line), 'order' => $order];
        } elseif ($block === 'required' && $existing['kind'] === 'preferred') {
            $found[$id]['kind'] = 'required';
        }
    }

    /** @param array{group: ?int, key: string} $candidate */
    private function frequency(string $text, array $candidate): int
    {
        $tokens = Tokens::of($text);
        $keys = $candidate['group'] !== null ? $this->taxonomy->keys($candidate['group']) : [$candidate['key']];
        $count = 0;
        foreach ($keys as $key) {
            $count += count(Tokens::find($tokens, explode(' ', $key), $this->taxonomy->guarded($key)));
        }

        return max(1, $count);
    }

    private function clip(string $line): string
    {
        return mb_strlen($line) <= 200 ? $line : rtrim(mb_substr($line, 0, 199)).'…';
    }

    /** @return array<string, true> */
    private function blocklist(): array
    {
        if ($this->blocklist === null) {
            $this->blocklist = [];
            foreach (['en', 'fr'] as $language) {
                $path = resource_path("ats/keyword-blocklist.{$language}.txt");
                foreach (is_file($path) ? preg_split('/\R/u', (string) file_get_contents($path), -1, PREG_SPLIT_NO_EMPTY) : [] as $word) {
                    if (($word = trim($word)) !== '' && ! str_starts_with($word, '#')) {
                        $this->blocklist[Tokens::key($word)] = true;
                    }
                }
            }
        }

        return $this->blocklist;
    }
}
