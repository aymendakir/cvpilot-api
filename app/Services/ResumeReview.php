<?php
namespace App\Services;

class ResumeReview
{
    public static function parse(string $answer, string $cv): ?array
    {
        $answer = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($answer));
        $data = json_decode($answer, true);
        if (!is_array($data) || !is_string($data['summary'] ?? null) || !is_array($data['suggestions'] ?? null)) return null;
        $lines = array_map('trim', preg_split('/\r?\n/', $cv));
        $suggestions = [];
        $seen = [];
        foreach ($data['suggestions'] as $item) {
            if (!is_array($item)) continue;
            foreach (['original', 'replacement', 'reason', 'category'] as $key) {
                if (!isset($item[$key]) || !is_string($item[$key])) continue 2;
            }
            $original = trim($item['original']);
            $replacement = trim($item['replacement']);
            if ($original === '' || $replacement === '' || $original === $replacement || !in_array($original, $lines, true) || isset($seen[$original])) continue;
            preg_match_all('/\d+(?:[.,]\d+)?/u', $original, $before);
            preg_match_all('/\d+(?:[.,]\d+)?/u', $replacement, $after);
            if (array_diff($after[0], $before[0])) continue;
            if (!in_array($item['category'], ['Clarity', 'Impact', 'Grammar', 'Repetition'], true)) $item['category'] = 'Clarity';
            $suggestions[] = ['original'=>$original, 'replacement'=>$replacement, 'reason'=>mb_substr($item['reason'],0,1000), 'category'=>$item['category']];
            $seen[$original] = true;
            if (count($suggestions) >= 6) break;
        }
        return ['summary'=>mb_substr($data['summary'],0,3000), 'suggestions'=>$suggestions];
    }
}
