<?php

namespace Tests\Unit\Ats;

use App\Services\Ats\Language\LanguageDetector;
use App\Services\Ats\Language\Normalizer;
use App\Services\Ats\Language\Stemmer;
use App\Services\Ats\Language\StopWords;
use App\Services\Ats\Language\Tokenizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** `ats-language` (SPEC-ats.md §2, §6.1, §8.3). */
class LanguageTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../fixtures/ats';

    public function test_normalizer_keeps_the_display_form_and_folds_for_comparison(): void
    {
        $n = new Normalizer;

        $this->assertSame("Expérience professionnelle - d'été", $n->display("Expérience\u{00A0}professionnelle \u{2013} d\u{2019}été"));
        $this->assertSame('experience professionnelle', $n->fold('EXPÉRIENCE  Professionnelle'));
        $this->assertSame('developpe', $n->fold('Développé'));
        $this->assertSame('fix', $n->fold("\u{FB01}x"), 'NFKC splits ligatures');
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function tokenCases(): array
    {
        return [
            'tech tokens stay whole' => ['Backend in C# and .NET, Node.js, CI/CD, A/B testing, C++', ['backend', 'in', 'c#', 'and', '.net', 'node.js', 'ci/cd', 'a/b', 'testing', 'c++']],
            'sentence punctuation is not part of a token' => ['I use Laravel. Also PHP, MySQL!', ['i', 'use', 'laravel', 'also', 'php', 'mysql']],
            'hyphens and apostrophes split words' => ["full-stack d'expérience l\u{2019}équipe", ['full', 'stack', 'd', 'expérience', 'l', 'équipe']],
            'Java and JavaScript are different tokens' => ['JavaScript developer, Java', ['javascript', 'developer', 'java']],
            'numbers and versions' => ['PHP 8.3, 40% faster, Vue3', ['php', '8.3', '40', 'faster', 'vue3']],
        ];
    }

    #[DataProvider('tokenCases')]
    public function test_tokenizer(string $text, array $tokens): void
    {
        $this->assertSame($tokens, (new Tokenizer)->tokens($text));
    }

    public function test_technical_tokens_are_recognised(): void
    {
        foreach (['c#', '.net', 'node.js', 'ci/cd', 'vue3', '8.3'] as $token) {
            $this->assertTrue(Tokenizer::isTechnical($token), $token);
        }
        foreach (['laravel', 'expérience', 'go'] as $token) {
            $this->assertFalse(Tokenizer::isTechnical($token), $token);
        }
    }

    public function test_stop_words_are_compared_without_accents(): void
    {
        $s = new StopWords;

        $this->assertTrue($s->contains('The', 'en'));
        $this->assertTrue($s->contains('à', 'fr'));
        $this->assertTrue($s->contains('a', 'fr'), 'folded: "à" matches "a"');
        $this->assertTrue($s->contains('Été', 'fr'));
        $this->assertFalse($s->contains('laravel', 'en'));
        $this->assertFalse($s->contains('projet', 'fr'));
    }

    public function test_stems_are_folded_and_technical_tokens_are_not_stemmed(): void
    {
        $s = new Stemmer;

        $this->assertSame('manag', $s->stem('managing', 'en'));
        $this->assertSame('developp', $s->stem('développement', 'fr'));
        $this->assertSame('node.js', $s->stem('Node.js', 'en'));
        $this->assertSame('c#', $s->stem('C#', 'en'));
        $this->assertSame('laravel', $s->stem('Laravel', 'other'), 'unsupported language: folded, not stemmed');
    }

    /**
     * §8.3 stem facts (the spec asks S1 to verify them against the library). Content words are stemmed,
     * stop words dropped; "matches" means the job term's stem sequence appears in the CV text's.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: bool}>
     */
    public static function stemFacts(): array
    {
        return [
            'managing teams ~ managed a team' => ['managing teams', 'managed a team of 6', 'en', true],
            'testing ~ tested' => ['testing', 'tested payment APIs', 'en', true],
            'gestion de projet ~ gestion de projets' => ['gestion de projet', 'gestion de projets agiles', 'fr', true],
            'développement ~ développé' => ['développement', 'développé une API', 'fr', true],
            'React ≠ reactive' => ['React', 'reactive programming with RxJS', 'en', false],
            'Java ≠ JavaScript' => ['Java', 'JavaScript developer', 'en', false],
            'SQL ≠ PostgreSQL' => ['SQL', 'PostgreSQL administration', 'en', false],
            // Contradicts the §8.3 "no match" row: Snowball stems "going" to "go". The S2 matcher must not
            // stem terms of 3 letters or less (nor technical tokens), which restores the expected result.
            'Go ~ going (stems equal; S2 rule needed)' => ['Go', 'going to the office daily', 'en', true],
        ];
    }

    #[DataProvider('stemFacts')]
    public function test_section_8_3_stem_facts(string $term, string $cv, string $language, bool $matches): void
    {
        $stems = function (string $text) use ($language) {
            $out = [];
            foreach ((new Tokenizer)->tokens($text) as $token) {
                if (! (new StopWords)->contains($token, $language)) {
                    $out[] = (new Stemmer)->stem($token, $language);
                }
            }

            return $out;
        };
        $needle = $stems($term);
        $haystack = $stems($cv);
        $found = false;
        for ($i = 0; $i + count($needle) <= count($haystack); $i++) {
            if (array_slice($haystack, $i, count($needle)) === $needle) {
                $found = true;
            }
        }

        $this->assertSame($matches, $found, json_encode(['term' => $needle, 'cv' => $haystack]));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function languageCases(): array
    {
        $cv = fn (string $file) => (string) file_get_contents(self::FIXTURES.'/'.$file);
        $profile = function (string $name) {
            $c = require self::FIXTURES."/content/{$name}.php";

            return $c['summary'].' '.implode(' ', array_merge(...array_map(fn ($j) => [$j['about'], ...$j['bullets']], $c['experience'])));
        };

        return [
            'BASE-EN' => [$profile('base-en'), 'en'],
            'BASE-FR' => [$profile('base-fr'), 'fr'],
            'F9 pasted text' => [$cv('cvs/no-email-no-exp.txt'), 'en'],
            'Spanish' => ['Desarrollador backend con siete años de experiencia en aplicaciones web para equipos de producto pequeños. Me importa el código legible, el diseño cuidadoso de bases de datos y las pruebas automatizadas.', 'other'],
            'German' => ['Backend-Entwickler mit sieben Jahren Erfahrung in der Entwicklung zuverlässiger Webanwendungen für kleine Produktteams. Mir sind lesbarer Code, sorgfältiges Datenbankdesign und automatisierte Tests wichtig.', 'other'],
            'too short' => ['PHP Laravel MySQL Docker', 'other'],
        ];
    }

    public function test_foreign_markers_never_overlap_the_english_or_french_stop_words(): void
    {
        $markers = (new \ReflectionClassConstant(LanguageDetector::class, 'OTHER_MARKERS'))->getValue();
        foreach ($markers as $word) {
            foreach (['en', 'fr'] as $language) {
                $this->assertFalse((new StopWords)->contains($word, $language), "{$word} is a {$language} stop word");
            }
        }
    }

    #[DataProvider('languageCases')]
    public function test_language_detection(string $text, string $expected): void
    {
        $this->assertSame($expected, (new LanguageDetector)->detect($text));
    }
}
