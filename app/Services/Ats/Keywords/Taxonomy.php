<?php

namespace App\Services\Ats\Keywords;

/**
 * Skills and synonym groups (SPEC-ats.md §6.1 step 3a, §6.2) from resources/ats/skills.json and
 * synonyms.{en,fr}.json. A skill's name and aliases form one group; a synonym group that shares a
 * phrase with another group is merged into it. Both synonym files are always loaded: the job
 * description and the CV can be in different languages.
 */
final class Taxonomy
{
    /** @var array<string, int> phrase key → group id */
    private array $groupOf = [];

    /** @var array<int, array{phrases: list<string>, keys: list<string>, skills: list<string>, anywhere: bool}> */
    private array $groups = [];

    /** @var array<string, true> keys that only count when written with a capital letter */
    private array $capitalised = [];

    /** @var list<array{key: string, group: int}> */
    private array $scannable = [];

    public function __construct(?string $directory = null)
    {
        $directory ??= resource_path('ats');
        $skills = json_decode((string) file_get_contents("{$directory}/skills.json"), true, flags: JSON_THROW_ON_ERROR)['skills'];

        /** @var list<array{phrases: list<string>, skill: ?string, anywhere: bool}> $raw */
        $raw = [];
        foreach ($skills as $skill) {
            $raw[] = ['phrases' => [$skill['name'], ...$skill['aliases']], 'skill' => $skill['name'], 'anywhere' => $skill['anywhere']];
            foreach ($skill['capitalised'] ?? [] as $phrase) {
                $this->capitalised[Tokens::key($phrase)] = true;
            }
        }
        foreach (['en', 'fr'] as $language) {
            $path = "{$directory}/synonyms.{$language}.json";
            foreach (is_file($path) ? json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['groups'] : [] as $phrases) {
                $raw[] = ['phrases' => $phrases, 'skill' => null, 'anywhere' => false];
            }
        }
        $this->build($raw);
    }

    /** @param list<array{phrases: list<string>, skill: ?string, anywhere: bool}> $raw */
    private function build(array $raw): void
    {
        // Union-find over the raw groups: two groups sharing a phrase key become one.
        $parent = array_keys($raw);
        $root = function (int $i) use (&$parent): int {
            while ($parent[$i] !== $i) {
                $i = $parent[$i] = $parent[$parent[$i]];
            }

            return $i;
        };
        $owner = [];
        foreach ($raw as $i => $group) {
            foreach ($group['phrases'] as $phrase) {
                $key = Tokens::key($phrase);
                if (isset($owner[$key])) {
                    $parent[$root($i)] = $root($owner[$key]);
                } else {
                    $owner[$key] = $i;
                }
            }
        }
        foreach ($raw as $i => $group) {
            $id = $root($i);
            $this->groups[$id] ??= ['phrases' => [], 'keys' => [], 'skills' => [], 'anywhere' => false];
            foreach ($group['phrases'] as $phrase) {
                $key = Tokens::key($phrase);
                if (! in_array($key, $this->groups[$id]['keys'], true)) {
                    $this->groups[$id]['phrases'][] = $phrase;
                    $this->groups[$id]['keys'][] = $key;
                }
                $this->groupOf[$key] = $id;
                if ($group['skill'] !== null && $group['anywhere'] && ! $this->guarded($key)) {
                    $this->scannable[] = ['key' => $key, 'group' => $id];
                }
            }
            if ($group['skill'] !== null) {
                $this->groups[$id]['skills'][] = $group['skill'];
                $this->groups[$id]['anywhere'] = $this->groups[$id]['anywhere'] || $group['anywhere'];
            }
        }
        // Longest phrases first, so "google cloud platform" is found before "google cloud".
        usort($this->scannable, fn ($a, $b) => substr_count($b['key'], ' ') <=> substr_count($a['key'], ' ') ?: strcmp($a['key'], $b['key']));
    }

    public function groupOf(string $phrase): ?int
    {
        return $this->groupOf[Tokens::key($phrase)] ?? null;
    }

    /** Every phrase key of a group, the skill name first. @return list<string> */
    public function keys(int $group): array
    {
        return $this->groups[$group]['keys'] ?? [];
    }

    /** @return array<int, array{phrases: list<string>, keys: list<string>, skills: list<string>, anywhere: bool}> */
    public function groups(): array
    {
        return $this->groups;
    }

    /**
     * A phrase that only counts when written with capitals: listed as `capitalised` in skills.json
     * (vue, tableau, spring…) or a single word of 1–2 letters (go, js, ai).
     */
    public function guarded(string $key): bool
    {
        return isset($this->capitalised[$key]) || preg_match('/^\p{L}{1,2}$/u', $key) === 1;
    }

    /**
     * Skill phrases that may be picked up from free text: from skills with anywhere=true, not guarded,
     * longest first. @return list<array{key: string, group: int}>
     */
    public function scannable(): array
    {
        return $this->scannable;
    }
}
