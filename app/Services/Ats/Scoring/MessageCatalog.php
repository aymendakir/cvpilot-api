<?php

namespace App\Services\Ats\Scoring;

use Illuminate\Contracts\Translation\Translator;

/**
 * EN/FR text of the report (SPEC-ats.md §5.2; lang/{en,fr}/ats.php). Every sentence is a whole entry
 * in the catalog with named placeholders; nothing is assembled from fragments. A text with "|"
 * choices is picked by its `count` (Laravel trans_choice).
 */
final class MessageCatalog
{
    public const LOCALES = ['en', 'fr'];

    private readonly Translator $translator;

    public function __construct(private readonly string $locale = 'en', ?Translator $translator = null)
    {
        $this->translator = $translator ?? app('translator');
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function categoryTitle(string $category): string
    {
        return $this->get("categories.{$category}");
    }

    public function checkTitle(string $checkId): string
    {
        return $this->get("checks.{$checkId}.title");
    }

    /** What was observed. Unverified results share texts unless a check has its own. */
    public function finding(string $checkId, string $key, array $params = []): string
    {
        $own = "checks.{$checkId}.findings.{$key}";

        return $this->has($own) ? $this->get($own, $params) : $this->get("unverified.{$key}", $params);
    }

    /** What to do about a failed check, or null when the check passes. */
    public function action(string $checkId, string $key, array $params = []): ?string
    {
        $path = "checks.{$checkId}.actions.{$key}";

        return $this->has($path) ? $this->get($path, $params) : null;
    }

    /** @return array{title: string, detail: string, action: string} */
    public function suggestion(Suggestion $suggestion): array
    {
        if (str_starts_with($suggestion->checkId, 'keyword_') && $suggestion->checkId !== 'keyword_coverage') {
            $base = "keywords.{$suggestion->checkId}".($suggestion->checkId === 'keyword_missing' ? ".{$suggestion->key}" : '');

            return [
                'title' => $this->get("{$base}.title", $suggestion->params),
                'detail' => $this->get("{$base}.detail", $suggestion->params),
                'action' => $this->get("{$base}.action", $suggestion->params),
            ];
        }
        if ($suggestion->checkId === 'keyword_coverage') {
            $base = 'keywords.insufficient_job_description';

            return [
                'title' => $this->get("{$base}.title", $suggestion->params),
                'detail' => $this->get("{$base}.detail", $suggestion->params),
                'action' => $this->get("{$base}.action", $suggestion->params),
            ];
        }

        return [
            'title' => $this->get("checks.{$suggestion->checkId}.suggestion"),
            'detail' => $this->get("checks.{$suggestion->checkId}.why"),
            'action' => (string) $this->action($suggestion->checkId, $suggestion->key, $suggestion->params),
        ];
    }

    public function capReason(string $capId): string
    {
        return $this->get("caps.{$capId}");
    }

    /**
     * One sentence (S3 decision 27): from the status, else the top suggestion's gain, else the grade.
     *
     * @param  list<Suggestion>  $suggestions  ranked
     */
    public function summary(ScoreResult $score, array $suggestions, int $words = 0): string
    {
        if ($score->status !== 'scored') {
            return $this->get("summary.{$score->status}", ['words' => $words]);
        }
        $params = ['score' => $score->score, 'verdict' => $this->get("summary.verdicts.{$score->grade}")];
        $top = $suggestions[0] ?? null;
        if ($top !== null && $top->impactPoints > 0) {
            return $this->get('summary.top_gain', $params + ['fix' => $this->fix($top), 'count' => $top->impactPoints]);
        }

        return $this->get($suggestions === [] ? 'summary.all_passed' : 'summary.no_gain', $params);
    }

    /** The short "what to fix" phrase used inside the summary sentence. */
    private function fix(Suggestion $suggestion): string
    {
        return $suggestion->checkId === 'keyword_missing'
            ? $this->get('summary.fix_keyword', $suggestion->params)
            : $this->get("checks.{$suggestion->checkId}.fix");
    }

    public function limitation(string $key): string
    {
        return $this->get("limitations.{$key}");
    }

    public function formattingNote(string $signal, string $key, array $params = []): string
    {
        return $this->get("formatting.{$signal}.{$key}", $params);
    }

    public function has(string $path): bool
    {
        return $this->translator->has("ats.{$path}", $this->locale, false);
    }

    public function get(string $path, array $params = []): string
    {
        $params = $this->format($params);
        $line = $this->translator->get("ats.{$path}", [], $this->locale, false);
        if (! is_string($line)) {
            throw new \LogicException("ats.{$path} is not a message ({$this->locale})");
        }
        if (str_contains($line, '|') && isset($params['count'])) {
            return $this->translator->choice("ats.{$path}", (int) $params['count'], $params, $this->locale);
        }

        return $this->translator->get("ats.{$path}", $params, $this->locale, false);
    }

    /** Locale-aware parameter values: file types in capitals, French decimal commas, date styles. */
    private function format(array $params): array
    {
        foreach ($params as $name => $value) {
            $params[$name] = match (true) {
                $name === 'type' && is_string($value) => strtoupper($value),
                $name === 'styles' || $name === 'style' => implode(', ', array_map(
                    fn ($s) => $this->translator->get('ats.date_styles.'.trim($s), [], $this->locale, false),
                    explode(',', (string) $value),
                )),
                is_float($value) => $this->locale === 'fr' ? str_replace('.', ',', (string) $value) : (string) $value,
                $value === null => '',
                default => (string) $value,
            };
        }

        return $params;
    }
}
