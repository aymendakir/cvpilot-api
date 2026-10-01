<?php

namespace App\Services\Ats\Keywords;

use App\Services\Ats\Language\Tokenizer;

/**
 * Finds a job keyword in the CV (SPEC-ats.md §6.2): exact (the term's token sequence) → synonym (another
 * phrase of its taxonomy group) → stem (Snowball stems of the term or a group phrase, stop words
 * ignored, same order). Token boundaries always apply ("Java" is not in "JavaScript"). A single-word
 * term of ≤ 3 letters and any term with a technical token (c#, node.js, ci/cd) is never stem-matched,
 * and guarded words (Go, Vue, Tableau…) only count when written with a capital.
 */
final class KeywordMatcher
{
    private const SECTION_ORDER = ['experience', 'skills', 'education', 'other'];

    private const EVIDENCE_LINES = 2;

    private const EVIDENCE_CHARS = 200;

    public function __construct(private readonly Taxonomy $taxonomy = new Taxonomy) {}

    public function match(JobKeyword $keyword, CvIndex $cv): KeywordMatch
    {
        $key = Tokens::key($keyword->term);
        $synonyms = $keyword->group !== null ? array_values(array_diff($this->taxonomy->keys($keyword->group), [$key])) : [];

        foreach (['exact' => [$key], 'synonym' => $synonyms] as $type => $phrases) {
            $hits = $this->find($cv, $phrases, 'tokens');
            if ($hits !== []) {
                return $this->result($keyword, $type, $hits, $cv);
            }
        }
        if ($cv->stemmable()) {
            $stemmable = array_values(array_filter([$key, ...$synonyms], fn ($k) => $this->stemmable($k)));
            $hits = $this->find($cv, $stemmable, 'stems');
            if ($hits !== []) {
                return $this->result($keyword, 'stem', $hits, $cv);
            }
        }

        return new KeywordMatch($keyword, null);
    }

    /** How many times the term or one of its synonyms occurs in the CV (stuffing, R6). */
    public function occurrences(JobKeyword $keyword, CvIndex $cv): int
    {
        $keys = $keyword->group !== null ? $this->taxonomy->keys($keyword->group) : [Tokens::key($keyword->term)];

        return count($this->find($cv, $keys, 'tokens'));
    }

    private function stemmable(string $key): bool
    {
        $tokens = explode(' ', $key);
        foreach ($tokens as $token) {
            if (Tokenizer::isTechnical($token)) {
                return false;
            }
        }

        return count($tokens) > 1 || mb_strlen($tokens[0]) > 3;
    }

    /**
     * Every occurrence of any phrase: `[line index, wording]`, in CV order.
     *
     * @param  list<string>  $phrases  phrase keys
     * @param  'tokens'|'stems'  $field
     * @return list<array{0: int, 1: string}>
     */
    private function find(CvIndex $cv, array $phrases, string $field): array
    {
        $hits = [];
        foreach ($phrases as $phrase) {
            $guarded = $this->taxonomy->guarded($phrase);
            $tokens = $field === 'stems' ? $cv->stems(Tokens::of($phrase)) : Tokens::of($phrase);
            $keys = array_column($tokens, 'key');
            if ($keys === []) {
                continue;
            }
            foreach ($cv->lines as $i => $line) {
                foreach (Tokens::find($line[$field], $keys, $guarded) as $start) {
                    $from = $line[$field][$start]['start'];
                    $to = $line[$field][$start + count($keys) - 1]['end'];
                    $hits[] = [$i, substr($line['text'], $from, $to - $from), $from];
                }
            }
        }
        usort($hits, fn ($a, $b) => [$a[0], $a[2]] <=> [$b[0], $b[2]]);

        return array_map(fn ($h) => [$h[0], $h[1]], $hits);
    }

    /** @param list<array{0: int, 1: string}> $hits */
    private function result(JobKeyword $keyword, string $type, array $hits, CvIndex $cv): KeywordMatch
    {
        // Experience lines first (the strongest evidence), then skills, education, other; CV order within.
        $rank = array_flip(self::SECTION_ORDER);
        usort($hits, fn ($a, $b) => [$rank[$cv->lines[$a[0]]['section']], $a[0]] <=> [$rank[$cv->lines[$b[0]]['section']], $b[0]]);
        $lines = array_values(array_unique(array_column($hits, 0)));
        $sections = array_unique(array_map(fn ($i) => $cv->lines[$i]['section'], $lines));
        $evidence = array_map(fn ($i) => $this->clip(trim($cv->lines[$i]['text'])), array_slice($lines, 0, self::EVIDENCE_LINES));

        return new KeywordMatch(
            $keyword,
            $type,
            $hits[0][1],
            array_values(array_intersect(self::SECTION_ORDER, $sections)),
            $evidence,
        );
    }

    private function clip(string $line): string
    {
        return mb_strlen($line) <= self::EVIDENCE_CHARS ? $line : rtrim(mb_substr($line, 0, self::EVIDENCE_CHARS - 1)).'…';
    }
}
