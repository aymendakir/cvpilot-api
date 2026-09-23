<?php
namespace App\Services;

class AtsScorer {
    private array $stop = [
        'with','that','this','your','from','have','will','work','team','and','the','our','are','experience',
        'years','skills','role','position','candidate','company','dans','pour','avec','vous','nous','une','des',
        'les','sur','aux','de','la','le','un','et','en','to','of','in','on','for','is','be','as','at','or','it',
        'we','you','job','required','requirement','requirements','preferred','benefit','benefits','salary',
        'opportunity','join','apply','please','including','plus','ideal','looking','seeking','about','us','who'
    ];

    private array $techLexicon = [
        'React' => ['react', 'reactjs', 'react.js'],
        'Next.js' => ['next.js', 'nextjs', 'next js'],
        'Vue.js' => ['vue', 'vuejs', 'vue.js'],
        'Angular' => ['angular', 'angularjs'],
        'TypeScript' => ['typescript', 'ts'],
        'JavaScript' => ['javascript', 'js'],
        'PHP' => ['php', 'php8', 'php7'],
        'Laravel' => ['laravel'],
        'Symfony' => ['symfony'],
        'Python' => ['python', 'python3'],
        'Django' => ['django'],
        'FastAPI' => ['fastapi'],
        'Flask' => ['flask'],
        'Java' => ['java', 'spring boot', 'spring'],
        'C#' => ['c#', 'csharp', '.net', 'asp.net'],
        'Go' => ['golang', 'go lang'],
        'Rust' => ['rust'],
        'SQL' => ['sql', 'mysql', 'postgresql', 'postgres'],
        'MongoDB' => ['mongodb', 'mongo'],
        'Redis' => ['redis'],
        'Docker' => ['docker', 'containers'],
        'Kubernetes' => ['kubernetes', 'k8s'],
        'AWS' => ['aws', 'amazon web services'],
        'Azure' => ['azure'],
        'GCP' => ['gcp', 'google cloud'],
        'CI/CD' => ['ci/cd', 'ci cd', 'continuous integration', 'github actions', 'gitlab ci'],
        'Git' => ['git', 'github', 'gitlab'],
        'REST APIs' => ['rest', 'rest api', 'restful', 'apis'],
        'GraphQL' => ['graphql'],
        'Tailwind CSS' => ['tailwind', 'tailwindcss'],
        'Unit Testing' => ['unit test', 'unit testing', 'jest', 'phpunit', 'pytest', 'cypress'],
        'Microservices' => ['microservices', 'microservice'],
        'Linux' => ['linux', 'ubuntu'],
        'Machine Learning' => ['machine learning', 'ml', 'ai', 'artificial intelligence']
    ];

    private array $softLexicon = [
        'Agile / Scrum' => ['agile', 'scrum', 'kanban', 'sprints'],
        'Problem Solving' => ['problem solving', 'troubleshooting', 'analytical'],
        'Leadership' => ['leadership', 'team lead', 'mentoring', 'managed'],
        'Communication' => ['communication', 'interpersonal'],
        'Collaboration' => ['collaboration', 'cross-functional', 'cross functional'],
        'Code Review' => ['code review', 'peer review']
    ];

    public function score(string $cv, string $job): array {
        $cvLower = mb_strtolower($cv);
        $jobLower = mb_strtolower($job);

        // 1. Curated Tech & Soft Skills
        $matchedTech = [];
        $missingTech = [];
        foreach ($this->techLexicon as $name => $aliases) {
            $inJob = false;
            foreach ($aliases as $alias) {
                if (str_contains($jobLower, $alias)) { $inJob = true; break; }
            }
            if (!$inJob) continue;

            $inCv = false;
            foreach ($aliases as $alias) {
                if (str_contains($cvLower, $alias)) { $inCv = true; break; }
            }

            if ($inCv) { $matchedTech[] = $name; }
            else { $missingTech[] = $name; }
        }

        $matchedSoft = [];
        $missingSoft = [];
        foreach ($this->softLexicon as $name => $aliases) {
            $inJob = false;
            foreach ($aliases as $alias) {
                if (str_contains($jobLower, $alias)) { $inJob = true; break; }
            }
            if (!$inJob) continue;

            $inCv = false;
            foreach ($aliases as $alias) {
                if (str_contains($cvLower, $alias)) { $inCv = true; break; }
            }

            if ($inCv) { $matchedSoft[] = $name; }
            else { $missingSoft[] = $name; }
        }

        // 2. Domain keywords
        $tokens = fn($v) => array_values(array_unique(array_filter(
            preg_split('/[^\pL\pN+#.\/-]+/u', mb_strtolower($v)),
            fn($x) => mb_strlen($x) >= 3 && !in_array($x, $this->stop, true) && !is_numeric($x)
        )));

        $jobTokens = array_slice($tokens($job), 0, 30);
        $cvTokens = array_flip($tokens($cv));

        $matchedDomain = [];
        $missingDomain = [];
        foreach ($jobTokens as $t) {
            if (isset($cvTokens[$t])) { $matchedDomain[] = ucfirst($t); }
            else { $missingDomain[] = ucfirst($t); }
        }

        $matchedAll = array_values(array_unique(array_merge($matchedTech, $matchedSoft, $matchedDomain)));
        $missingAll = array_values(array_unique(array_merge($missingTech, $missingSoft, $missingDomain)));

        // 3. Scores
        $totalTech = count($matchedTech) + count($missingTech);
        $techScore = $totalTech > 0 ? (count($matchedTech) / $totalTech) * 35 : (count($matchedDomain) / max(1, count($jobTokens))) * 35;
        $domainScore = (count($matchedDomain) / max(1, count($jobTokens))) * 10;
        $keyword = (int)round(min(45, $techScore + $domainScore));

        $softTotal = count($matchedSoft) + count($missingSoft);
        $soft = $softTotal > 0 ? (int)round((count($matchedSoft) / $softTotal) * 15) : 10;

        $sections = 0;
        foreach ([
            '/experience|employment|work history|expérience/i',
            '/skills|competencies|compétences|technologies/i',
            '/education|formation|diplôme|degree/i',
            '/[\w.+-]+@[\w.-]+\.[a-z]{2,}|(?:\+?\d[\s.-]?){8,}/i'
        ] as $p) {
            $sections += preg_match($p, $cv) ? 1 : 0;
        }

        $lines = array_filter(array_map('trim', preg_split('/\R/', $cv)));
        $long = count(array_filter($lines, fn($l) => mb_strlen($l) > 175));
        $words = str_word_count(strip_tags($cv));
        $readability = max(4, min(20, 20 - $long * 3 - ($words < 200 ? 5 : 0) - ($words > 1100 ? 4 : 0)));

        preg_match_all('/\b(built|created|developed|designed|implemented|launched|improved|increased|reduced|optimized|automated|managed|led|delivered|integrated|deployed|architected|engineered|scaled|spearheaded|développé|créé|conçu|amélioré|optimisé|géré|réalisé)\b/iu', $cv, $verbs);
        preg_match_all('/\b\d+(?:[.,]\d+)?\s*(?:%|k|m|million|hours?|days?|users?|clients?|projects?|ans?|mois)?\b/iu', $cv, $numbers);
        $impact = min(20, (int)round(min(count($verbs[0]), 8) * 1.5 + min(count($numbers[0]), 6)));

        $totalScore = max(15, min(99, $keyword + $soft + ($sections * 5) + $impact));

        $suggestions = [];
        foreach ($lines as $i => $line) {
            if (preg_match('/^(?:[-•*]\s*)?(responsible for|responsable de|worked on|travail sur|helped with)/iu', $line)) {
                $suggestions[] = ['line' => $i + 1, 'reason' => 'Start with a strong action verb instead of passive description.', 'original' => $line];
            } elseif (mb_strlen($line) > 175) {
                $suggestions[] = ['line' => $i + 1, 'reason' => 'Split this long bullet point into shorter, readable achievements.', 'original' => $line];
            }
            if (count($suggestions) >= 8) break;
        }

        return [
            'score' => $totalScore,
            'breakdown' => [
                'keywords' => $keyword,
                'soft_skills' => $soft,
                'sections' => $sections * 5,
                'readability' => $readability,
                'impact' => $impact
            ],
            'matched_keywords' => array_slice($matchedAll, 0, 24),
            'missing_keywords' => array_slice($missingAll, 0, 24),
            'technical' => ['matched' => $matchedTech, 'missing' => $missingTech],
            'soft' => ['matched' => $matchedSoft, 'missing' => $missingSoft],
            'suggestions' => $suggestions
        ];
    }
}
