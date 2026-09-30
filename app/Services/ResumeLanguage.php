<?php

namespace App\Services;

/** Language hints from the candidate's writing; job descriptions do not affect this choice. */
class ResumeLanguage
{
    private const MARKERS = [
        'French' => ['expérience', 'expériences', 'compétences', 'formation', 'développeur', 'développeuse', 'réalisé', 'réalisée', 'projets', 'parcours', 'entreprise', 'études', 'diplôme', 'depuis', 'pour', 'avec', 'chez', 'langues', 'stage'],
        'English' => ['experience', 'skills', 'education', 'developer', 'developed', 'projects', 'employment', 'responsibilities', 'university', 'degree', 'with', 'from', 'for', 'achieved', 'managed'],
        'Spanish' => ['experiencia', 'habilidades', 'educación', 'desarrollador', 'desarrolladora', 'proyectos', 'formación', 'empresa', 'universidad', 'logros', 'idiomas', 'trabajo', 'con', 'para'],
    ];

    public static function detect(string $cv): ?string
    {
        if (preg_match_all('/[\p{Arabic}]/u', $cv) >= 12) {
            return 'Arabic';
        }
        $text = mb_strtolower($cv, 'UTF-8');
        $counts = [];
        foreach (self::MARKERS as $language => $words) {
            $counts[$language] = 0;
            foreach ($words as $word) {
                $counts[$language] += preg_match_all('/(?<![\p{L}])'.preg_quote($word, '/').'(?![\p{L}])/u', $text);
            }
        }
        arsort($counts);
        $first = array_key_first($counts);
        $second = array_values($counts)[1];

        return $counts[$first] >= 2 && $counts[$first] > $second ? $first : null;
    }

    public static function clearlyEnglish(string $text): bool
    {
        $lower = mb_strtolower($text, 'UTF-8');
        $english = preg_match_all('/\b(?:the|this|your|you|should|with|from|which|have|make|these|their|because|clearer|improve)\b/u', $lower);
        $french = preg_match_all('/(?<![\p{L}])(?:votre|vous|avec|dans|pour|cette|les|une|des|compétences|expérience|améliorer|mieux)(?![\p{L}])/u', $lower);

        return $english >= 3 && $english > $french * 2;
    }
}
