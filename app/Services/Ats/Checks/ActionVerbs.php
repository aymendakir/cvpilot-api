<?php

namespace App\Services\Ats\Checks;

use App\Services\Ats\Language\Normalizer;
use App\Services\Ats\Language\Stemmer;
use App\Services\Ats\Language\Tokenizer;

/**
 * Does a bullet start with an action? English verbs, French verbs, and French action nouns
 * ("Développement de…", decision 3 of the S2 plan), compared on Snowball stems; multi-word entries
 * ("mis en place", "set up") are compared as folded phrases.
 */
final class ActionVerbs
{
    /** @var array{en: array<string, true>, fr: array<string, true>, phrases: list<string>}|null */
    private ?array $lists = null;

    public function __construct(
        private readonly Normalizer $normalizer = new Normalizer,
        private readonly Stemmer $stemmer = new Stemmer,
        private readonly Tokenizer $tokenizer = new Tokenizer,
    ) {}

    public function startsWithAction(string $bullet): bool
    {
        $lists = $this->lists();
        $folded = $this->normalizer->fold($bullet);
        foreach ($lists['phrases'] as $phrase) {
            if (str_starts_with($folded, $phrase.' ')) {
                return true;
            }
        }
        $first = $this->tokenizer->tokens($bullet)[0] ?? '';
        if ($first === '' || Tokenizer::isTechnical($first)) {
            return false;
        }

        return isset($lists['en'][$this->stemmer->stem($first, 'en')]) || isset($lists['fr'][$this->stemmer->stem($first, 'fr')]);
    }

    /** @return array{en: array<string, true>, fr: array<string, true>, phrases: list<string>} */
    private function lists(): array
    {
        if ($this->lists !== null) {
            return $this->lists;
        }
        $lists = ['en' => [], 'fr' => [], 'phrases' => []];
        foreach (['en' => ['action-verbs.en.txt'], 'fr' => ['action-verbs.fr.txt', 'action-nouns.fr.txt']] as $language => $files) {
            foreach ($files as $file) {
                foreach (preg_split('/\R/u', (string) file_get_contents(resource_path("ats/{$file}"))) as $entry) {
                    $entry = trim($entry);
                    if ($entry === '' || str_starts_with($entry, '#')) {
                        continue;
                    }
                    if (str_contains($entry, ' ')) {
                        $lists['phrases'][] = $this->normalizer->fold($entry);
                    } else {
                        $lists[$language][$this->stemmer->stem($entry, $language)] = true;
                    }
                }
            }
        }

        return $this->lists = $lists;
    }
}
