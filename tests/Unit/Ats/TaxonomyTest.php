<?php

namespace Tests\Unit\Ats;

use App\Services\Ats\Keywords\Taxonomy;
use App\Services\Ats\Keywords\Tokens;
use Tests\TestCase;

/** The keyword taxonomy files stay consistent (SPEC-ats.md §6.1, §6.2, §8.3; S2 decision 4). */
class TaxonomyTest extends TestCase
{
    /** @return list<array{name: string, category: string, aliases: list<string>, anywhere: bool}> */
    private function skills(): array
    {
        return json_decode((string) file_get_contents(resource_path('ats/skills.json')), true)['skills'];
    }

    public function test_every_skill_phrase_belongs_to_one_skill_only(): void
    {
        $seen = [];
        foreach ($this->skills() as $skill) {
            foreach ([$skill['name'], ...$skill['aliases']] as $phrase) {
                $key = Tokens::key($phrase);
                $this->assertNotSame('', $key, "{$skill['name']}: empty phrase");
                $this->assertArrayNotHasKey($key, $seen, "\"{$phrase}\" is in {$skill['name']} and ".($seen[$key] ?? ''));
                $seen[$key] = $skill['name'];
            }
        }
    }

    public function test_synonym_files_never_join_two_skills(): void
    {
        foreach ((new Taxonomy)->groups() as $group) {
            $this->assertLessThanOrEqual(1, count($group['skills']), 'merged skills: '.implode(', ', $group['skills']));
        }
    }

    public function test_each_synonym_phrase_is_in_one_group_of_its_file(): void
    {
        foreach (['en', 'fr'] as $language) {
            $seen = [];
            foreach (json_decode((string) file_get_contents(resource_path("ats/synonyms.{$language}.json")), true)['groups'] as $i => $group) {
                foreach ($group as $phrase) {
                    $key = Tokens::key($phrase);
                    $this->assertArrayNotHasKey($key, $seen, "synonyms.{$language}.json: \"{$phrase}\" in two groups");
                    $seen[$key] = $i;
                }
            }
        }
    }

    public function test_related_but_different_terms_are_never_grouped(): void
    {
        $taxonomy = new Taxonomy;
        // §8.3 guards and the legacy AtsScorer groups split in S2 (decision 4)
        foreach ([
            ['SQL', 'PostgreSQL'], ['SQL', 'MySQL'], ['MySQL', 'PostgreSQL'], ['Java', 'JavaScript'], ['Java', 'Spring'],
            ['React', 'reactive'], ['Docker', 'containers'], ['Machine Learning', 'Artificial Intelligence'],
            ['REST APIs', 'apis'], ['C#', '.NET'], ['Git', 'GitHub Actions'], ['Content Strategy', 'Content Marketing'],
        ] as [$a, $b]) {
            $ga = $taxonomy->groupOf($a);
            $this->assertNotNull($ga, "{$a} is a skill");
            $this->assertNotSame($ga, $taxonomy->groupOf($b), "{$a} and {$b} must not be synonyms");
        }
    }

    public function test_synonyms_from_the_spec_are_grouped(): void
    {
        $taxonomy = new Taxonomy;
        foreach ([
            ['JavaScript', 'js'], ['Kubernetes', 'k8s'], ['CI/CD', 'continuous integration'], ['Node.js', 'node'],
            ['gestion de projet', 'project management'], ['Vue.js', 'vue'], ['Git', 'GitHub'], ['REST APIs', 'RESTful API'],
            ['Unit Testing', 'tests unitaires'], ['revue de code', 'code review'],
        ] as [$a, $b]) {
            $this->assertNotNull($taxonomy->groupOf($a), "{$a} is in the taxonomy");
            $this->assertSame($taxonomy->groupOf($a), $taxonomy->groupOf($b), "{$a} and {$b} are synonyms");
        }
    }

    public function test_ambiguous_words_are_guarded_and_not_scanned(): void
    {
        $taxonomy = new Taxonomy;
        $scanned = array_column($taxonomy->scannable(), 'key');
        foreach (['go', 'vue', 'tableau', 'spring', 'rust', 'js', 'ai', 'ml', 'ts'] as $key) {
            $this->assertTrue($taxonomy->guarded($key), "{$key} needs a capital");
            $this->assertNotContains($key, $scanned);
        }
        foreach (['php', 'laravel', 'docker', 'vue.js', 'project management', 'gestion de projet'] as $key) {
            $this->assertContains($key, $scanned);
        }
        $this->assertNotContains('code review', $scanned, 'synonym-only phrases are not skills');
    }
}
