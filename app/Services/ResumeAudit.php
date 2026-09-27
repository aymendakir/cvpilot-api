<?php
namespace App\Services;

/** Validate AI observations against the supplied documents before displaying them. */
class ResumeAudit
{
    public static function parse(string $answer, string $cv, string $job = ''): ?array
    {
        $clean = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($answer));
        $data = json_decode($clean, true);
        $base = ResumeReview::parse($answer, $cv);
        if (!$base || !is_array($data)) return null;
        $quote = static function ($value, string $source): ?string {
            if (!is_string($value) || trim($value) === '' || mb_strlen($value) > 600) return null;
            return str_contains($source, trim($value)) ? trim($value) : null;
        };
        $strengths = [];
        foreach (array_slice(is_array($data['strengths'] ?? null) ? $data['strengths'] : [], 0, 4) as $item) {
            if (!is_array($item) || !is_string($item['title'] ?? null)) continue;
            if ($evidence = $quote($item['evidence'] ?? null, $cv)) $strengths[] = ['title'=>mb_substr($item['title'],0,180), 'evidence'=>$evidence];
        }
        $priorities = [];
        foreach (array_slice(is_array($data['priorities'] ?? null) ? $data['priorities'] : [], 0, 3) as $item) {
            if (!is_array($item) || !is_string($item['title'] ?? null) || !is_string($item['action'] ?? null)) continue;
            if ($evidence = $quote($item['evidence'] ?? null, $cv)) $priorities[] = ['title'=>mb_substr($item['title'],0,180), 'action'=>mb_substr($item['action'],0,700), 'evidence'=>$evidence];
        }
        $rubric = [];
        foreach (['clarity','specificity','relevance','organization'] as $key) {
            $item = $data['rubric'][$key] ?? null;
            if (!is_array($item) || !is_int($item['level'] ?? null) || $item['level'] < 0 || $item['level'] > 4 || !is_string($item['reason'] ?? null)) continue;
            if ($evidence = $quote($item['evidence'] ?? null, $cv)) $rubric[] = ['id'=>$key, 'level'=>$item['level'], 'reason'=>mb_substr($item['reason'],0,700), 'evidence'=>$evidence];
        }
        preg_match_all('/[\p{L}\p{N}]+/u', $cv, $words);
        $score = count($rubric) === 4 && count($words[0]) >= 80 ? (int) round(array_sum(array_column($rubric,'level')) / 16 * 100) : null;
        $requirements = [];
        if (trim($job) !== '') foreach (array_slice(is_array($data['requirements'] ?? null) ? $data['requirements'] : [],0,8) as $item) {
            if (!is_array($item) || !in_array($item['status'] ?? '', ['supported','partial','not_found'], true)) continue;
            $requirement = $quote($item['requirement'] ?? null, $job);
            $evidence = $quote($item['evidence'] ?? null, $cv);
            if (!$requirement || ($item['status'] !== 'not_found' && !$evidence)) continue;
            $requirements[] = ['requirement'=>$requirement, 'status'=>$item['status'], 'evidence'=>$evidence];
        }
        return $base + ['strengths'=>$strengths,'priorities'=>$priorities,'rubric'=>$rubric,'score'=>$score,'requirements'=>$requirements,'version'=>'ai-review-1'];
    }
}
