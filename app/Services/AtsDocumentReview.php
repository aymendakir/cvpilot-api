<?php

namespace App\Services;

/**
 * Observable text checks.
 * This cannot emulate any employer's proprietary ATS.
 */
class AtsDocumentReview
{
    public const VERSION = 'document-5.1-fixed';

    public function analyze(string $text, ?string $fileName = null): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

        $lines = array_values(
            array_filter(
                array_map('trim', explode("\n", $text)),
                fn ($line) => $line !== ''
            )
        );

        preg_match_all('/[\p{L}\p{N}]+/u', $text, $m);
        $wordCount = count($m[0]);

        $french = ResumeLanguage::detect($text) === 'French';

        $clean = static fn (string $line): string => mb_strtolower(
            preg_replace('/\s+/u', ' ', trim($line)),
            'UTF-8'
        );

        $categories = [];

        $add = static function (
            string $id,
            string $title,
            int $max,
            float $fraction,
            array $evidence,
            string $finding,
            string $action
        ) use (&$categories): void {

            $earned = (int) round(
                max(0, min(1, $fraction)) * $max
            );

            $categories[count($categories) - 1]['checks'][] = [
                'id' => $id,
                'title' => $title,
                'status' => $earned === $max ? 'pass' : 'review',
                'earned' => $earned,
                'max' => $max,
                'finding' => $finding,
                'action' => $action,
                'evidence' => array_slice(
                    array_values(
                        array_filter(
                            $evidence,
                            static fn ($line) => trim((string) $line) !== ''
                        )
                    ),
                    0,
                    2
                ),
            ];
        };

        $group = static function (
            string $id,
            string $title,
            string $description
        ) use (&$categories): void {
            $categories[] = [
                'id' => $id,
                'title' => $title,
                'description' => $description,
                'checks' => [],
            ];
        };

        /*
        |--------------------------------------------------------------------------
        | Heading detection
        |--------------------------------------------------------------------------
        */

        $headingType = static function (string $line) use ($clean): ?string {
            $raw = trim($line, " \t\n\r\0\x0B:-–—|");

            if (mb_strlen($raw) > 80 || mb_strlen($raw) < 2) {
                return null;
            }

            $lineClean = $clean($raw);
            $lineClean = trim(
                $lineClean,
                " \t\n\r\0\x0B:-–—|"
            );

            $norm = str_replace(
                ['&', '+'],
                ' et ',
                $lineClean
            );

            $norm = preg_replace('/\s+/u', ' ', $norm);
            $norm = trim($norm);

            /*
            |--------------------------------------------------------------------------
            | Projects / Experience
            |--------------------------------------------------------------------------
            */

            if (
                preg_match(
                    '/^projets?\s+techniques?$/u',
                    $lineClean
                )
            ) {
                return 'experience';
            }

            if (str_contains($norm, 'projets techniques')) {
                return 'experience';
            }

            if (
                preg_match(
                    '/^stages?\s*(?:et|&)\s*expériences?$/u',
                    $norm
                )
            ) {
                return 'experience';
            }

            if (
                str_contains($norm, 'stages') &&
                str_contains($norm, 'exp')
            ) {
                return 'experience';
            }

            /*
            | Additional flexible experience detection
            */

            if (
                (
                    str_contains($norm, 'expérience') ||
                    str_contains($norm, 'experience')
                ) &&
                (
                    str_contains($norm, 'professionnel') ||
                    str_contains($norm, 'stage') ||
                    str_contains($norm, 'projet')
                )
            ) {
                return 'experience';
            }

            if (
                (
                    str_contains($norm, 'projet') ||
                    str_contains($norm, 'project')
                ) &&
                (
                    str_contains($norm, 'technique') ||
                    str_contains($norm, 'personnel') ||
                    str_contains($norm, 'academic') ||
                    str_contains($norm, 'académique')
                )
            ) {
                return 'experience';
            }

            /*
            |--------------------------------------------------------------------------
            | Education
            |--------------------------------------------------------------------------
            */

            if (
                preg_match(
                    '/^(?:education|éducation|formation|formations|études|academic background|diplômes?|scolarité)$/u',
                    $lineClean
                )
            ) {
                return 'education';
            }

            /*
            |--------------------------------------------------------------------------
            | Skills
            |--------------------------------------------------------------------------
            */

            if (
                preg_match(
                    '/^(?:(?:technical|professional|core|key)\s+)?(?:skills|competencies|competences|technologies|tools|compétences(?: techniques)?|outils|savoir[- ]faire)(?:\s+techniques?)?$/u',
                    $lineClean
                )
            ) {
                return 'skills';
            }

            /*
            |--------------------------------------------------------------------------
            | Standard experience
            |--------------------------------------------------------------------------
            */

            if (
                preg_match(
                    '/^(?:(?:professional|work|relevant|clinical|teaching|sales|volunteer)\s+)?(?:experience|experiences|employment|work history|career history|projects|expérience(?:s)?(?: professionnelle(?:s)?)?|parcours professionnel|projets?|stages?|bénévolat|missions?)(?:\s*(?:techniques?|professionnelles?))?$/u',
                    $lineClean
                )
            ) {
                return 'experience';
            }

            /*
            |--------------------------------------------------------------------------
            | Other headings
            |--------------------------------------------------------------------------
            */

            if (
                preg_match(
                    '/^(?:summary|profile|profil|résumé|about me|objective|objectif|languages|langues|certifications|certificats|licenses|licences|contact|references|références|publications|awards|distinctions)$/u',
                    $lineClean
                )
            ) {
                return 'other';
            }

            return null;
        };

        /*
        |--------------------------------------------------------------------------
        | Build sections
        |--------------------------------------------------------------------------
        */

        $sections = [
            'experience' => [],
            'education' => [],
            'skills' => [],
        ];

        $headings = [];
        $section = null;

        foreach ($lines as $line) {
            $type = $headingType($line);

            if ($type) {
                $section = $type === 'other'
                    ? null
                    : $type;

                $headings[$type] = $line;

                continue;
            }

            if ($section) {
                $sections[$section][] = $line;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Helpers
        |--------------------------------------------------------------------------
        */

        $wordCountOf = static function (string $line): int {
            preg_match_all(
                '/[\p{L}\p{N}]+/u',
                $line,
                $mm
            );

            return count($mm[0]);
        };

        /*
        |--------------------------------------------------------------------------
        | Contributions
        |--------------------------------------------------------------------------
        */

        // Minimum 4 words so short technical bullets are still recognized.
        $contributions = array_values(
            array_filter(
                $sections['experience'],
                static fn (string $line): bool => $wordCountOf($line) >= 4 &&
                    ! preg_match(
                        '/^[\d\s\/–—.,-]+$/u',
                        $line
                    )
            )
        );

        $key = static fn (string $line): string => preg_replace(
            '/[^\p{L}\p{N}]+/u',
            ' ',
            mb_strtolower(
                preg_replace(
                    '/^[-•*·▪–\s]+|^\(?\d+[.)]\s*/u',
                    '',
                    $line
                ),
                'UTF-8'
            )
        );

        $unique = [];
        $dupes = [];

        foreach ($contributions as $line) {
            $k = trim($key($line));

            if (isset($unique[$k])) {
                $dupes[] = $line;
            } else {
                $unique[$k] = $line;
            }
        }

        $unique = array_values($unique);

        /*
        |--------------------------------------------------------------------------
        | Parsing checks
        |--------------------------------------------------------------------------
        */

        $missingChars = array_values(
            array_filter(
                $lines,
                static fn (string $line): bool => str_contains($line, "\u{FFFD}")
            )
        );

        $fragmented = array_values(
            array_filter(
                $lines,
                static fn (string $line): bool => preg_match('/^\p{L}$/u', $line) === 1
            )
        );

        $letterSpaced = array_values(
            array_filter(
                $lines,
                static fn (string $line): bool => preg_match(
                    '/(?:\b\p{L}\s+){5,}\p{L}\b/u',
                    $line
                ) === 1
            )
        );

        $email = array_values(
            array_filter(
                $lines,
                static fn (string $line): bool => preg_match(
                    '/[\w.+-]+@[\w.-]+\.[a-z]{2,}/iu',
                    $line
                ) === 1
            )
        );

        $dated = array_values(
            array_filter(
                $sections['experience'],
                static fn (string $line): bool => preg_match(
                    '/\b(?:19|20)\d{2}\b/',
                    $line
                ) === 1
            )
        );

        /*
        |--------------------------------------------------------------------------
        | Action verbs
        |--------------------------------------------------------------------------
        */

        $actionVerbPattern = '/\b(?:'
            .'built|developed|developped|designed|implemented|delivered|managed|led|created|improved|reduced|increased|organized|supported|resolved|maintained|tested|prepared|trained|coordinated|automated'
            .'|sold|negotiated|closed|pitched|upsold|prospected|onboarded'
            .'|marketed|launched|branded|promoted|advertised|grew|generated'
            .'|budgeted|forecasted|audited|reconciled|invoiced|analyzed|reported'
            .'|diagnosed|treated|assessed|monitored|administered|counseled'
            .'|taught|mentored|tutored|advised|supervised|coached|facilitated'
            .'|assisted|handled|responded(?:\s+to)?|processed|scheduled|dispatched'
            .'|streamlined|optimized|planned|executed|oversaw|directed|drafted|litigated|filed'
            .'|recruited|hired|interviewed|evaluated'
            .'|développé|développée|développer'
            .'|conçu|conçue|concevoir'
            .'|créé|créée|créer'
            .'|amélioré|améliorée|améliorer'
            .'|réalisé|réalisée|réaliser'
            .'|optimisé|optimisée|optimiser'
            .'|géré|gérée|gérer'
            .'|coordonné|coordonnée|coordonner'
            .'|livré|livrée|livrer'
            .'|piloté|pilotée|piloter'
            .'|formé|formée|former'
            .'|configuré|configurée|configurer'
            .'|installé|installée|installer'
            .'|déployé|déployée|déployer'
            .'|administré|administrée|administrer'
            .'|sécurisé|sécurisée|sécuriser'
            .'|supervisé|supervisée|superviser'
            .'|maintenu|maintenir'
            .'|collaboré|collaborer'
            .'|vendu|vendue|vendre'
            .'|négocié|négociée|négocier'
            .'|prospecté|prospectée|prospecter'
            .'|commercialisé|commercialisée|commercialiser'
            .'|lancé|lancée|lancer'
            .'|promu|promue|promouvoir'
            .'|budgétisé|budgétiser'
            .'|audité|auditée|auditer'
            .'|réconcilié'
            .'|analysé|analysée|analyser'
            .'|diagnostiqué'
            .'|traité|traitée|traiter'
            .'|suivi|suivie|suivre'
            .'|conseillé|conseillée|conseiller'
            .'|enseigné|enseignée|enseigner'
            .'|encadré|encadrée|encadrer'
            .'|recruté|recrutée|recruter'
            .'|embauché|embauchée|embaucher'
            .'|évalué|évaluée|évaluer'
            .')\b/iu';

        $actionNouns =
            '/\b(?:développement|création|intégration|conception|configuration|déploiement|implémentation|installation|administration|collaboration|utilisation|résolution|maintenance|participation|gestion|coordination|organisation|analyse|réalisation|préparation|formation|enseignement|vente|négociation|recrutement|supervision|accompagnement|assistance|amélioration|traitement|livraison|suivi|mise en place|sécurisation)\b/iu';

        $actions = array_values(
            array_filter(
                $unique,
                static fn (string $line): bool => preg_match($actionVerbPattern, $line) === 1 ||
                    preg_match($actionNouns, $line) === 1
            )
        );

        $weak = array_values(
            array_filter(
                $unique,
                static fn (string $line): bool => preg_match(
                    '/\b(?:responsible for|duties included|in charge of|tasked with|responsable de|chargé de|chargée de)\b/iu',
                    $line
                ) === 1
            )
        );

        $concise = array_values(
            array_filter(
                $unique,
                static fn (string $line): bool => $wordCountOf($line) <= 45
            )
        );

        /*
        |--------------------------------------------------------------------------
        | Outcomes / measurable context
        |--------------------------------------------------------------------------
        */

        $outcomes = array_values(
            array_filter(
                $unique,
                static fn (string $line): bool => preg_match(
                    '/\b(?:resulting in|enabled|reduced|increased|improved|saved|to improve|to reduce|to enable|to simplify|permettant|réduit|amélioré|facilité|optimisé|afin de|pour améliorer|pour faciliter)\b|\d+(?:[.,]\d+)?\s*(?:%|clients?|users?|utilisateurs?|projets?|projects?|heures?|hours?|patients?|élèves?|students?|k\$?|€|\$|k€)\b/iu',
                    $line
                ) === 1
            )
        );

        $count = count($unique);

        /*
        |--------------------------------------------------------------------------
        | Extraction
        |--------------------------------------------------------------------------
        */

        $group(
            'extraction',
            $french ? 'Lecture du fichier' : 'Extracted text',
            $french
                ? 'Qualité du texte extrait; la mise en page visuelle reste à vérifier.'
                : 'Text extraction quality; visual layout still needs your review.'
        );

        $add(
            'readable',
            $french ? 'Texte exploitable' : 'Readable text',
            5,
            $wordCount >= 60 ? 1 : $wordCount / 60,
            [],
            $french
                ? "{$wordCount} mots lisibles détectés."
                : "{$wordCount} readable words detected.",
            $french
                ? 'Vérifiez le texte extrait : un scan image nécessite un OCR.'
                : 'Review extracted text; image scans need OCR.'
        );

        $add(
            'characters',
            $french ? 'Caractères lisibles' : 'Character integrity',
            5,
            $missingChars ? 0 : 1,
            $missingChars,
            $missingChars
                ? ($french
                    ? 'Caractères illisibles trouvés.'
                    : 'Unreadable replacement characters found.')
                : ($french
                    ? 'Aucun caractère de remplacement détecté.'
                    : 'No replacement characters detected.'),
            $french
                ? 'Réexportez le PDF avec une couche de texte.'
                : 'Export a text-based PDF.'
        );

        $add(
            'fragmentation',
            $french ? 'Ordre de lecture' : 'Reading continuity',
            5,
            count($fragmented) <= 2 && ! $letterSpaced ? 1 : 0,
            [...$fragmented, ...$letterSpaced],
            $french
                ? count($fragmented).' lettres isolées, '.count($letterSpaced).' ligne(s) avec des mots espacés lettre par lettre.'
                : count($fragmented).' isolated letters, '.count($letterSpaced).' line(s) with letter-spaced words.',
            $french
                ? 'Vérifiez les colonnes et remplacez les titres espacés lettre par lettre par du texte normal.'
                : 'Inspect columns and replace letter-spaced headings with normal selectable text.'
        );

        /*
        |--------------------------------------------------------------------------
        | Contact
        |--------------------------------------------------------------------------
        */

        $group(
            'contact',
            $french ? 'Coordonnées' : 'Contact',
            $french
                ? 'Ce que le texte permet de trouver; coordonnées non vérifiées.'
                : 'Only observable contact text; delivery is not verified.'
        );

        $add(
            'email',
            $french ? 'Adresse e-mail' : 'Email address',
            10,
            $email ? 1 : 0,
            $email,
            $email
                ? ($french ? 'Adresse détectée.' : 'Address detected.')
                : ($french
                    ? 'Aucune adresse e-mail lisible trouvée.'
                    : 'No readable email found.'),
            $french
                ? 'Ajoutez une adresse e-mail visible dans le CV.'
                : 'Add a visible email address.'
        );

        /*
        |--------------------------------------------------------------------------
        | Structure
        |--------------------------------------------------------------------------
        */

        $group(
            'structure',
            $french ? 'Structure des rubriques' : 'Sections',
            $french
                ? 'Une rubrique compte seulement si du contenu lisible la suit.'
                : 'A heading counts only with readable content beneath it.'
        );

        $add(
            'experience',
            $french ? 'Expérience ou projets' : 'Experience or projects',
            10,
            count($sections['experience']) >= 2 ? 1 : 0,
            [
                $headings['experience'] ?? '',
                ...$sections['experience'],
            ],
            isset($headings['experience'])
                ? ($french
                    ? 'Rubrique reconnue; '.count($sections['experience']).' ligne(s) dessous.'
                    : 'Heading recognized; '.count($sections['experience']).' lines beneath it.')
                : ($french
                    ? 'Rubrique Expérience / Projets introuvable.'
                    : 'Experience / Projects heading not recognized.'),
            $french
                ? 'Utilisez une rubrique standard et ajoutez rôles, projets et réalisations.'
                : 'Use a standard heading and add roles, projects and contributions.'
        );

        $educationContent = array_filter(
            $sections['education'],
            static fn ($s) => $wordCountOf($s) >= 3
        );

        $add(
            'education',
            $french ? 'Formation' : 'Education',
            5,
            $educationContent ? 1 : 0,
            [
                $headings['education'] ?? '',
                ...$sections['education'],
            ],
            isset($headings['education'])
                ? ($french
                    ? 'Rubrique reconnue; vérification du contenu.'
                    : 'Heading recognized; checking content.')
                : ($french
                    ? 'Rubrique Formation introuvable.'
                    : 'Education / Formation heading not recognized.'),
            $french
                ? 'Indiquez diplôme ou établissement sous Formation.'
                : 'Add degree or institution beneath Education.'
        );

        $skillsContent = array_filter(
            $sections['skills'],
            static fn ($s) => $wordCountOf($s) >= 2
        );

        $add(
            'skills',
            $french ? 'Compétences' : 'Skills',
            5,
            $skillsContent ? 1 : 0,
            [
                $headings['skills'] ?? '',
                ...$sections['skills'],
            ],
            isset($headings['skills'])
                ? ($french
                    ? 'Rubrique reconnue; vérification du contenu.'
                    : 'Heading recognized; checking content.')
                : ($french
                    ? 'Rubrique Compétences introuvable.'
                    : 'Skills / Compétences heading not recognized.'),
            $french
                ? 'Listez seulement les compétences réellement maîtrisées.'
                : 'List only skills you actually have.'
        );

        $add(
            'dates',
            $french ? 'Dates d’expérience' : 'Experience dates',
            5,
            $dated ? 1 : 0,
            $dated,
            $dated
                ? ($french
                    ? 'Une année apparaît sous Expérience.'
                    : 'A year appears under Experience.')
                : ($french
                    ? 'Aucune année reconnue sous Expérience.'
                    : 'No year recognized under Experience.'),
            $french
                ? 'Ajoutez une période vérifiable à chaque poste ou projet.'
                : 'Add a verifiable period to each role or project.'
        );

        /*
        |--------------------------------------------------------------------------
        | Experience evidence
        |--------------------------------------------------------------------------
        */

        $group(
            'evidence',
            $french ? 'Expérience démontrée' : 'Experience evidence',
            $french
                ? 'Qualité des contributions observables, sans exiger de chiffres inventés.'
                : 'Observable contributions; no invented metrics are required.'
        );

        $add(
            'substance',
            $french ? 'Contributions distinctes' : 'Distinct contributions',
            15,
            min($count / 3, 1),
            $unique,
            $french
                ? "{$count} contributions distinctes de 4 mots ou plus."
                : "{$count} distinct contributions of 4+ words.",
            $french
                ? 'Décrivez trois tâches ou réalisations précises.'
                : 'Describe three specific responsibilities or outcomes.'
        );

        $add(
            'ownership',
            $french ? 'Actions précises' : 'Clear actions',
            10,
            $count ? count($actions) / $count : 0,
            $actions,
            $french
                ? count($actions)." sur {$count} contributions contiennent une action reconnue."
                : count($actions)." of {$count} contributions contain a recognized action.",
            $french
                ? 'Décrivez votre contribution avec un verbe fidèle à votre rôle, quel que soit votre domaine.'
                : 'State your contribution with a truthful action verb, whatever your field.'
        );

        $add(
            'specificity',
            $french ? 'Formulations précises' : 'Concrete wording',
            5,
            $count ? 1 - count($weak) / $count : 0,
            $weak,
            $weak
                ? ($french
                    ? 'Formulations de responsabilité génériques détectées.'
                    : 'Generic responsibility phrases detected.')
                : ($french
                    ? 'Aucune formule générique détectée.'
                    : 'No generic duty phrases detected.'),
            $french
                ? 'Remplacez les formules générales par votre action réelle.'
                : 'Replace generic duties with your actual work.'
        );

        $add(
            'focus',
            $french ? 'Phrases lisibles' : 'Focused sentences',
            5,
            $count ? count($concise) / $count : 0,
            array_values(
                array_diff($unique, $concise)
            ),
            $french
                ? count($concise)." sur {$count} contributions font 45 mots ou moins."
                : count($concise)." of {$count} contributions are 45 words or fewer.",
            $french
                ? 'Divisez les phrases longues pour faciliter la lecture.'
                : 'Split long contribution sentences.'
        );

        $add(
            'repetition',
            $french ? 'Sans répétition' : 'No duplicates',
            5,
            $contributions
                ? 1 - count($dupes) / count($contributions)
                : 0,
            $dupes,
            $dupes
                ? ($french
                    ? 'Lignes identiques trouvées.'
                    : 'Repeated contribution lines found.')
                : ($french
                    ? 'Aucune contribution répétée.'
                    : 'No repeated contribution lines.'),
            $french
                ? 'Supprimez les doublons, gardez des preuves variées.'
                : 'Remove duplicate lines; keep varied evidence.'
        );

        /*
        |--------------------------------------------------------------------------
        | Outcomes
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        | A CV should not lose the entire category simply because it doesn't
        | contain percentages or numerical metrics.
        |
        */

        $outcomeFraction = $count > 0
            ? min(1, 0.4 + (count($outcomes) * 0.3))
            : 0;

        $add(
            'outcomes',
            $french ? 'Résultats ou contexte' : 'Outcomes or context',
            10,
            $outcomeFraction,
            $outcomes,
            $french
                ? count($outcomes).' ligne(s) évoquent un résultat, un contexte ou une échelle.'
                : count($outcomes).' line(s) mention an outcome, context, or scale.',
            $french
                ? 'Ajoutez si possible des résultats ou du contexte véridique. Les chiffres ne sont pas obligatoires.'
                : 'Add truthful results or context when possible. Numbers are not required.'
        );

        /*
        |--------------------------------------------------------------------------
        | Category scores
        |--------------------------------------------------------------------------
        */

        foreach ($categories as &$category) {
            $category['score'] = array_sum(
                array_column(
                    $category['checks'],
                    'earned'
                )
            );

            $category['max'] = array_sum(
                array_column(
                    $category['checks'],
                    'max'
                )
            );
        }

        unset($category);

        $checks = array_merge(
            ...array_column($categories, 'checks')
        );

        $raw = array_sum(
            array_column($checks, 'earned')
        );

        $issues = array_values(
            array_filter(
                $checks,
                static fn ($c) => $c['status'] === 'review'
            )
        );

        /*
        |--------------------------------------------------------------------------
        | Final score
        |--------------------------------------------------------------------------
        */

        $score = $wordCount < 40
            ? null
            : min(94, $raw);

        /*
        | Critical document problems
        */

        if ($score !== null && ! $email) {
            $score = min($score, 82);
        }

        if (
            $score !== null &&
            ! isset($headings['experience'])
        ) {
            $score = min($score, 80);
        }

        if (
            $score !== null &&
            $count < 2
        ) {
            $score = min($score, 84);
        }

        if (
            $score !== null &&
            (
                $missingChars ||
                count($fragmented) > 2
            )
        ) {
            $score = min($score, 72);
        }

        /*
        | Only meaningful issues block 90+.
        | Minor review items should not automatically cap a strong CV at 89.
        */

        $significantIssues = array_values(
            array_filter(
                $checks,
                static fn (array $check): bool => ($check['max'] - $check['earned']) >= 4
            )
        );

        if (
            $score !== null &&
            count($significantIssues) >= 2
        ) {
            $score = min($score, 89);
        }

        /*
        |--------------------------------------------------------------------------
        | Priorities
        |--------------------------------------------------------------------------
        */

        $priorities = $issues;

        usort(
            $priorities,
            static fn ($a, $b) => ($b['max'] - $b['earned'])
                <=>
                ($a['max'] - $a['earned'])
        );

        /*
        |--------------------------------------------------------------------------
        | Score explanation
        |--------------------------------------------------------------------------
        */

        if ($score === null) {
            $scoreReason = $french
                ? 'Texte insuffisant : au moins 40 mots lisibles sont nécessaires.'
                : 'Insufficient text: at least 40 readable words are needed.';
        } elseif ($score >= 90) {
            $scoreReason = $french
                ? 'Très bonne qualité textuelle et structurelle. Quelques améliorations mineures peuvent encore être possibles.'
                : 'Very strong textual and structural quality. Minor improvements may still be possible.';
        } elseif ($issues) {
            $scoreReason = $french
                ? 'Le CV est exploitable, mais certains contrôles peuvent encore être améliorés.'
                : 'The CV is usable, but some checks can still be improved.';
        } else {
            $scoreReason = $french
                ? 'Tous les contrôles textuels passent ; le résultat réel dépend toujours du système de recrutement utilisé.'
                : 'All text checks pass; real-world results still depend on the recruiting system used.';
        }

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        return [
            'version' => self::VERSION,
            'score' => $score,
            'raw_score' => $raw,
            'max_score' => array_sum(
                array_column($checks, 'max')
            ),
            'words' => $wordCount,
            'issues' => count($issues),
            'categories' => $categories,
            'priorities' => array_slice(
                $priorities,
                0,
                4
            ),
            'score_reason' => $scoreReason,
            'limitations' => [
                $french
                    ? 'Indicateur de qualité du texte, pas un score ATS employeur.'
                    : 'Text quality indicator, not an employer ATS score.',

                $french
                    ? 'La mise en page visuelle, la véracité et le recrutement restent non vérifiés.'
                    : 'Visual layout, factual claims and hiring outcome remain unverified.',
            ],
        ];
    }
}
