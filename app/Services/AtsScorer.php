<?php

namespace App\Services;

class AtsScorer {
    private array $stop = [
        'with','that','this','your','from','have','will','work','team','and','the','our','are','experience',
        'years','skills','role','position','candidate','company','dans','pour','avec','vous','nous','une','des',
        'les','sur','aux','de','la','le','un','et','en','to','of','in','on','for','is','be','as','at','or','it',
        'we','you','job','required','requirement','requirements','preferred','benefit','benefits','salary',
        'opportunity','join','apply','please','including','plus','ideal','looking','seeking','about','us','who',
        'responsibilities','duties','qualifications','essential','desirable','strong','excellent','ability',
        'knowledge','environment','ensure','across','into','well','high','must','other','than'
    ];

    // Every professional field is checked on equal footing so the score isn't biased toward dev roles.
    // A skill only counts as "in the job" if its alias literally appears in the posting text, so a
    // marketing job is never scored against Docker/Kubernetes and a dev job is never scored against SEO.
    private array $domainLexicons = [
        'Tech / Development' => [
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
        ],
        'Data & Analytics' => [
            'Microsoft Excel' => ['microsoft excel', 'excel spreadsheets', 'pivot table', 'pivot tables', 'vlookup', 'tableur'],
            'Tableau' => ['tableau'],
            'Power BI' => ['power bi', 'powerbi'],
            'Data Analysis' => ['data analysis', 'data analytics', 'analyse de données'],
            'A/B Testing' => ['a/b testing', 'ab testing', 'split testing'],
            'Statistics' => ['statistics', 'statistical analysis', 'statistiques'],
            'Data Visualization' => ['data visualization', 'dashboards', 'dataviz']
        ],
        'Marketing' => [
            'SEO' => ['seo', 'search engine optimization', 'référencement'],
            'SEM / PPC' => ['sem', 'ppc', 'google ads', 'adwords', 'paid advertising'],
            'Google Analytics' => ['google analytics', 'ga4'],
            'Content Marketing' => ['content marketing', 'content strategy', 'stratégie de contenu'],
            'Social Media Marketing' => ['social media marketing', 'social media management', 'réseaux sociaux'],
            'Email Marketing' => ['email marketing', 'mailchimp', 'newsletter'],
            'Copywriting' => ['copywriting', 'copywriter', 'rédaction publicitaire'],
            'Brand Strategy' => ['brand strategy', 'branding', 'image de marque'],
            'Marketing Automation' => ['marketing automation', 'hubspot'],
            'Growth Marketing' => ['growth marketing', 'growth hacking']
        ],
        'Sales' => [
            'CRM Software' => ['crm', 'salesforce'],
            'Lead Generation' => ['lead generation', 'prospecting', 'prospection'],
            'Cold Outreach' => ['cold calling', 'cold outreach', 'cold emailing'],
            'Negotiation' => ['negotiation', 'négociation'],
            'Account Management' => ['account management', 'key account'],
            'Pipeline Management' => ['sales pipeline', 'pipeline management'],
            'B2B / B2C Sales' => ['b2b', 'b2c'],
            'Quota Attainment' => ['quota', 'sales targets', 'objectifs de vente'],
            'Upselling & Cross-selling' => ['upselling', 'cross-selling', 'cross selling', 'vente incitative']
        ],
        'Design & Creative' => [
            'Figma' => ['figma'],
            'Adobe Creative Suite' => ['photoshop', 'illustrator', 'indesign', 'adobe creative'],
            'Sketch' => ['sketch'],
            'UI/UX Design' => ['ui/ux', 'ui design', 'ux design', 'user experience'],
            'Wireframing & Prototyping' => ['wireframing', 'wireframes', 'prototyping', 'prototypage'],
            'Typography' => ['typography', 'typographie'],
            'Motion Design' => ['motion design', 'after effects'],
            'Design Systems' => ['design system', 'design systems']
        ],
        'Finance & Accounting' => [
            'Financial Modeling' => ['financial modeling', 'financial models'],
            'Budgeting & Forecasting' => ['budgeting', 'forecasting', 'budgétisation'],
            'GAAP / IFRS' => ['gaap', 'ifrs'],
            'Accounts Payable/Receivable' => ['accounts payable', 'accounts receivable'],
            'Reconciliation' => ['reconciliation', 'bank reconciliation', 'rapprochement bancaire'],
            'SAP' => ['sap'],
            'QuickBooks' => ['quickbooks'],
            'Auditing' => ['auditing', 'audit'],
            'Taxation' => ['taxation', 'tax compliance', 'fiscalité']
        ],
        'HR & Recruiting' => [
            'Talent Acquisition' => ['talent acquisition', 'recruiting', 'recrutement'],
            'Onboarding' => ['onboarding'],
            'HRIS' => ['hris', 'workday', 'bamboohr'],
            'Performance Management' => ['performance management', 'performance reviews', 'évaluation de performance'],
            'Payroll' => ['payroll', 'paie'],
            'Compensation & Benefits' => ['compensation', 'benefits administration', 'rémunération'],
            'Applicant Tracking Systems' => ['applicant tracking system', 'ats']
        ],
        'Customer Support & Success' => [
            'Zendesk' => ['zendesk'],
            'Customer Service' => ['customer service', 'customer support', 'service client'],
            'Ticketing / Help Desk' => ['ticketing', 'help desk', 'helpdesk'],
            'SLA Management' => ['sla', 'service level agreement'],
            'CSAT / NPS' => ['csat', 'nps', 'customer satisfaction', 'satisfaction client'],
            'Customer Retention' => ['customer retention', 'churn reduction', 'fidélisation'],
            'Live Chat Support' => ['live chat']
        ],
        'Operations & Supply Chain' => [
            'Supply Chain Management' => ['supply chain', 'chaîne d\'approvisionnement'],
            'Inventory Management' => ['inventory management', 'stock management', 'gestion des stocks'],
            'Procurement' => ['procurement', 'sourcing', 'achats'],
            'Logistics' => ['logistics', 'logistique'],
            'ERP Systems' => ['erp'],
            'Lean / Six Sigma' => ['lean six sigma', 'lean manufacturing', 'six sigma'],
            'Vendor Management' => ['vendor management', 'supplier management']
        ],
        'Project Management' => [
            'PMP Certification' => ['pmp', 'project management professional'],
            'PM Tools' => ['jira', 'trello', 'asana', 'monday.com'],
            'Gantt Charts' => ['gantt'],
            'Risk Management' => ['risk management', 'gestion des risques'],
            'Stakeholder Management' => ['stakeholder management', 'parties prenantes']
        ],
        'Legal' => [
            'Litigation' => ['litigation', 'contentieux'],
            'Contract Law' => ['contract law', 'contract drafting', 'contract review', 'droit des contrats'],
            'Legal Research' => ['legal research', 'recherche juridique'],
            'Compliance' => ['compliance', 'regulatory compliance', 'conformité'],
            'Due Diligence' => ['due diligence'],
            'Paralegal' => ['paralegal']
        ],
        'Healthcare' => [
            'Patient Care' => ['patient care', 'soins aux patients'],
            'EMR/EHR Systems' => ['emr', 'ehr', 'electronic health records', 'electronic medical records', 'dossier médical'],
            'HIPAA Compliance' => ['hipaa'],
            'Clinical Experience' => ['clinical experience', 'clinical skills'],
            'Medical Terminology' => ['medical terminology', 'terminologie médicale'],
            'Nursing' => ['nursing', 'registered nurse', 'infirmier', 'infirmière']
        ],
        'Education' => [
            'Curriculum Development' => ['curriculum development', 'curriculum design', 'conception de programmes'],
            'Lesson Planning' => ['lesson planning', 'lesson plans', 'préparation de cours'],
            'Classroom Management' => ['classroom management', 'gestion de classe'],
            'Student Assessment' => ['student assessment', 'évaluation des élèves'],
            'EdTech' => ['edtech', 'educational technology']
        ]
    ];

    private array $softLexicon = [
        'Agile / Scrum' => ['agile', 'scrum', 'kanban', 'sprints'],
        'Problem Solving' => ['problem solving', 'troubleshooting', 'analytical'],
        'Leadership' => ['leadership', 'team lead', 'mentoring', 'managed'],
        'Communication' => ['communication', 'interpersonal'],
        'Collaboration' => ['collaboration', 'cross-functional', 'cross functional'],
        'Code Review' => ['code review', 'peer review'],
        'Time Management' => ['time management', 'gestion du temps'],
        'Presentation Skills' => ['presentation skills', 'public speaking', 'prise de parole'],
        'Attention to Detail' => ['attention to detail', 'detail-oriented', 'detail oriented', 'rigueur'],
        'Adaptability' => ['adaptability', 'adaptable', 'adaptabilité'],
        'Multitasking' => ['multitasking', 'multi-tasking'],
        'Client Relations' => ['client relations', 'relation client'],
        'Strategic Thinking' => ['strategic planning', 'strategic thinking', 'pensée stratégique']
    ];

    // Word-boundary aware "contains" check: short aliases (ts, ai, rest, sla...) only count when
    // they appear as a standalone token, not embedded inside unrelated words like "results" or
    // "interested". Multi-word aliases are unaffected since they were never at risk of this.
    private function hasAlias(string $text, string $alias): bool {
        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($alias, '/') . '(?![\p{L}\p{N}])/ui';
        return (bool) preg_match($pattern, $text);
    }

    public function score(string $cv, string $job): array {
        $cvLower = mb_strtolower($cv);
        $jobLower = mb_strtolower($job);

        // 1. Hard / domain skills, checked across every field at once (see $domainLexicons above).
        $matchedHard = [];
        $missingHard = [];
        $domainHits = [];

        foreach ($this->domainLexicons as $domain => $skills) {
            foreach ($skills as $name => $aliases) {
                $inJob = false;
                foreach ($aliases as $alias) {
                    if ($this->hasAlias($jobLower, $alias)) { $inJob = true; break; }
                }
                if (!$inJob) continue;

                $domainHits[$domain] = ($domainHits[$domain] ?? 0) + 1;

                $inCv = false;
                foreach ($aliases as $alias) {
                    if ($this->hasAlias($cvLower, $alias)) { $inCv = true; break; }
                }

                if ($inCv) { $matchedHard[] = $name; }
                else { $missingHard[] = $name; }
            }
        }

        arsort($domainHits);
        $detectedDomain = array_key_first($domainHits); // null when the posting matched no curated lexicon at all

        $matchedSoft = [];
        $missingSoft = [];
        foreach ($this->softLexicon as $name => $aliases) {
            $inJob = false;
            foreach ($aliases as $alias) {
                if ($this->hasAlias($jobLower, $alias)) { $inJob = true; break; }
            }
            if (!$inJob) continue;

            $inCv = false;
            foreach ($aliases as $alias) {
                if ($this->hasAlias($cvLower, $alias)) { $inCv = true; break; }
            }

            if ($inCv) { $matchedSoft[] = $name; }
            else { $missingSoft[] = $name; }
        }

        // 2. Generic domain keyword overlap — fallback for any field with no curated lexicon above.
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

        $matchedAll = array_values(array_unique(array_merge($matchedHard, $matchedSoft, $matchedDomain)));
        $missingAll = array_values(array_unique(array_merge($missingHard, $missingSoft, $missingDomain)));

        // 3. Scores
        $totalHard = count($matchedHard) + count($missingHard);
        $hardScore = $totalHard > 0 ? (count($matchedHard) / $totalHard) * 35 : (count($matchedDomain) / max(1, count($jobTokens))) * 35;
        $domainScore = (count($matchedDomain) / max(1, count($jobTokens))) * 10;
        $keyword = (int)round(min(45, $hardScore + $domainScore));

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

        preg_match_all('/\b(built|created|developed|designed|implemented|launched|improved|increased|reduced|optimized|automated|managed|led|delivered|integrated|deployed|architected|engineered|scaled|spearheaded|achieved|exceeded|negotiated|coordinated|trained|mentored|resolved|streamlined|generated|presented|forecasted|budgeted|onboarded|facilitated|conducted|analyzed|researched|authored|drafted|advised|supervised|audited|reconciled|recruited|hired|closed|grew|drove|saved|développé|créé|conçu|amélioré|optimisé|géré|réalisé|négocié|coordonné|formé|encadré|résolu|généré|présenté|planifié|dirigé|analysé|rédigé|conseillé|supervisé|recruté|embauché|économisé)\b/iu', $cv, $verbs);
        preg_match_all('/\b\d+(?:[.,]\d+)?\s*(?:%|k|m|million|hours?|days?|users?|clients?|projects?|leads?|deals?|accounts?|patients?|students?|cases?|campaigns?|sales|revenue|ans?|mois)?\b/iu', $cv, $numbers);
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
            'technical' => ['matched' => $matchedHard, 'missing' => $missingHard],
            'soft' => ['matched' => $matchedSoft, 'missing' => $missingSoft],
            'detected_domain' => $detectedDomain,
            'suggestions' => $suggestions
        ];
    }
}