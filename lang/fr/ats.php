<?php

/*
 * Textes du rapport ATS, français (SPEC-ats.md §5.2). Les clés sont exactement celles de
 * lang/en/ats.php (AtsMessagesTest). Les textes « a|b » sont choisis selon :count.
 */

return [
    'categories' => [
        'format' => 'Format',
        'sections' => 'Sections',
        'content' => 'Contenu',
        'keywords' => 'Mots-clés de l\'offre',
    ],

    'checks' => [
        'readable_text' => [
            'title' => 'Texte lisible',
            'suggestion' => 'Rendez le texte lisible',
            'why' => 'Si un ATS ne peut pas lire le texte, rien d\'autre dans votre CV ne compte.',
            'fix' => 'rendre le texte lisible',
            'findings' => [
                'ok' => 'Le texte est lisible (:words mots).',
                'no_text' => 'Aucun texte n\'a pu être lu : le fichier ressemble à un scan ou à une image.',
                'garbled' => ':percent % des caractères sont illisibles.',
                'too_short' => 'Seulement :words mots ont pu être lus ; il en faut au moins 40 pour noter un CV.',
            ],
            'actions' => [
                'no_text' => 'Exportez à nouveau votre CV depuis votre éditeur en PDF ou DOCX contenant du vrai texte. Ne le scannez pas et ne le photographiez pas.',
                'garbled' => 'Exportez à nouveau avec une police standard (Arial, Calibri, Times New Roman), ou enregistrez-le en DOCX.',
                'too_short' => 'Écrivez votre expérience, votre formation et vos compétences sous forme de texte, pas d\'images.',
            ],
        ],
        'single_column' => [
            'title' => 'Mise en page sur une colonne',
            'suggestion' => 'Passez à une mise en page sur une colonne',
            'why' => 'Beaucoup d\'ATS lisent les colonnes ligne par ligne, ce qui mélange votre colonne latérale avec votre expérience.',
            'fix' => 'passer à une mise en page sur une colonne',
            'findings' => [
                'ok' => 'Aucune colonne détectée.',
                'columns' => 'La page utilise plusieurs colonnes de texte.',
            ],
            'actions' => [
                'columns' => 'Déplacez le contenu de la colonne latérale (compétences, coordonnées, langues) dans des sections au-dessus ou au-dessous de votre expérience, sur une seule colonne.',
            ],
        ],
        'layout_tables' => [
            'title' => 'Pas de tableaux de mise en page',
            'suggestion' => 'Supprimez les tableaux de mise en page',
            'why' => 'Les ATS ignorent souvent le texte placé dans un tableau ou le lisent dans le mauvais ordre.',
            'fix' => 'supprimer les tableaux de mise en page',
            'findings' => [
                'ok' => 'Aucun tableau contenant du texte.',
                'tables' => 'Une partie du texte est placée dans un tableau.',
            ],
            'actions' => [
                'tables' => 'Remplacez le tableau par des paragraphes et des listes à puces.',
            ],
        ],
        'images' => [
            'title' => 'Images',
            'suggestion' => 'Utilisez moins d\'images, et plus petites',
            'why' => 'Les ATS ignorent les images, et une grande image décale ou masque le texte.',
            'fix' => 'utiliser moins d\'images, et plus petites',
            'findings' => [
                'none' => 'Aucune image.',
                'small' => '{1} Une petite image, ce qui convient.|[2,*] :count petites images, ce qui convient.',
                'too_many' => ':count images (2 au maximum).',
                'too_large' => 'Une image couvre environ :largest_area_pct % de la page (15 % au maximum).',
            ],
            'actions' => [
                'too_many' => 'Gardez au plus une petite photo ou un logo, et supprimez les images et icônes décoratives.',
                'too_large' => 'Réduisez la photo ou supprimez-la, et ne mettez jamais de texte dans une image.',
            ],
        ],
        'text_boxes_headers' => [
            'title' => 'Zones de texte et en-têtes',
            'suggestion' => 'Sortez le contenu des zones de texte et des en-têtes',
            'why' => 'Beaucoup d\'ATS ignorent les zones de texte et les en-têtes ou pieds de page : des coordonnées placées là peuvent être perdues.',
            'fix' => 'sortir le contenu des zones de texte et des en-têtes',
            'findings' => [
                'ok' => 'Aucun texte dans des zones de texte, et vos coordonnées sont dans le corps de la page.',
                'text_boxes' => '{0} Des zones de texte contiennent du texte.|{1} Une zone de texte contient du texte.|[2,*] :count zones de texte contiennent du texte.',
                'contact_in_header' => 'Vos coordonnées apparaissent uniquement dans l\'en-tête ou le pied de page.',
            ],
            'actions' => [
                'text_boxes' => 'Sortez le texte des zones de texte et placez-le dans le corps normal de la page.',
                'contact_in_header' => 'Placez votre adresse e-mail et votre numéro de téléphone en haut du corps de la page, pas dans l\'en-tête ou le pied de page.',
            ],
        ],
        'file_supported' => [
            'title' => 'Type et taille du fichier',
            'suggestion' => 'Utilisez un fichier PDF ou DOCX de moins de 5 Mo',
            'why' => 'La plupart des ATS acceptent le PDF et le DOCX, et refusent souvent les fichiers très lourds.',
            'fix' => 'utiliser un fichier PDF ou DOCX de moins de 5 Mo',
            'findings' => [
                'ok' => 'Fichier :type, :megabytes Mo.',
                'too_large' => 'Le fichier fait :megabytes Mo (5 Mo au maximum).',
            ],
            'actions' => [
                'too_large' => 'Compressez ou supprimez les images, puis exportez à nouveau le CV.',
            ],
        ],
        'clean_characters' => [
            'title' => 'Caractères propres',
            'suggestion' => 'Remplacez les icônes et les caractères spéciaux',
            'why' => 'Dans un ATS, les polices d\'icônes et les titres aux lettres espacées deviennent des symboles étranges ou des mots coupés.',
            'fix' => 'remplacer les icônes et les caractères spéciaux',
            'findings' => [
                'ok' => 'Aucune icône, aucun caractère illisible ni titre aux lettres espacées.',
                'glyphs' => 'Icônes, caractères illisibles ou titres aux lettres espacées détectés (icônes : :private_use, illisibles : :replacement, titres espacés : :letter_spaced).',
            ],
            'actions' => [
                'glyphs' => 'Écrivez les libellés en toutes lettres (« E-mail », « Téléphone ») au lieu d\'icônes, et tapez les titres normalement (« Compétences », pas « C O M P É T E N C E S »).',
            ],
        ],
        'email' => [
            'title' => 'Adresse e-mail',
            'suggestion' => 'Ajoutez votre adresse e-mail',
            'why' => 'Les recruteurs en ont besoin pour vous contacter, et beaucoup d\'ATS l\'exigent.',
            'fix' => 'ajouter votre adresse e-mail',
            'findings' => [
                'found' => 'Trouvée : :email.',
                'missing' => 'Aucune adresse e-mail trouvée.',
            ],
            'actions' => [
                'missing' => 'Ajoutez une adresse e-mail professionnelle en haut de votre CV.',
            ],
        ],
        'phone' => [
            'title' => 'Numéro de téléphone',
            'suggestion' => 'Ajoutez votre numéro de téléphone',
            'why' => 'Les recruteurs appellent souvent avant d\'écrire.',
            'fix' => 'ajouter votre numéro de téléphone',
            'findings' => [
                'found' => 'Trouvé : :phone.',
                'missing' => 'Aucun numéro de téléphone trouvé.',
            ],
            'actions' => [
                'missing' => 'Ajoutez votre numéro avec l\'indicatif du pays (par exemple +212) en haut de votre CV.',
            ],
        ],
        'experience_section' => [
            'title' => 'Section expérience',
            'suggestion' => 'Ajoutez une section expérience clairement titrée',
            'why' => 'Les ATS cherchent un titre standard pour trouver votre parcours ; sans lui, votre expérience risque de ne pas être reconnue comme telle.',
            'fix' => 'ajouter une section expérience clairement titrée',
            'findings' => [
                'found' => 'Titre trouvé : « :heading » (ligne :line).',
                'missing' => 'Aucun titre d\'expérience trouvé (par exemple « Expérience professionnelle » ou « Projets »).',
                'empty' => 'Le titre « :heading » n\'est suivi d\'aucun contenu.',
            ],
            'actions' => [
                'missing' => 'Placez votre parcours sous un titre standard comme « Expérience professionnelle » ou « Projets ».',
                'empty' => 'Listez vos postes sous « :heading » : intitulé, entreprise, dates et 2 à 5 puces chacun.',
            ],
        ],
        'education_section' => [
            'title' => 'Section formation',
            'suggestion' => 'Ajoutez une section formation',
            'why' => 'Beaucoup de filtres de recrutement vérifient les diplômes et les écoles, que les ATS trouvent sous un titre standard.',
            'fix' => 'ajouter une section formation',
            'findings' => [
                'found' => 'Titre trouvé : « :heading » (ligne :line).',
                'missing' => 'Aucun titre de formation trouvé (par exemple « Formation »).',
                'empty' => 'Le titre « :heading » n\'est suivi d\'aucun contenu.',
            ],
            'actions' => [
                'missing' => 'Ajoutez une section « Formation » avec votre diplôme, l\'établissement et les dates.',
                'empty' => 'Listez vos diplômes sous « :heading » : diplôme, établissement et dates.',
            ],
        ],
        'skills_section' => [
            'title' => 'Section compétences',
            'suggestion' => 'Ajoutez une section compétences',
            'why' => 'Les ATS et les recruteurs cherchent une liste de compétences pour vous rapprocher de l\'offre.',
            'fix' => 'ajouter une section compétences',
            'findings' => [
                'found' => 'Titre trouvé : « :heading » (ligne :line).',
                'missing' => 'Aucun titre de compétences trouvé (par exemple « Compétences »).',
                'empty' => 'Le titre « :heading » n\'est suivi d\'aucun contenu.',
            ],
            'actions' => [
                'missing' => 'Ajoutez une section « Compétences » qui liste vos principaux outils et savoir-faire.',
                'empty' => 'Listez vos principaux outils et savoir-faire sous « :heading ».',
            ],
        ],
        'dates' => [
            'title' => 'Dates',
            'suggestion' => 'Utilisez des dates claires et homogènes',
            'why' => 'Les ATS calculent vos années d\'expérience à partir des périodes ; des dates absentes ou de formats mélangés sont mal lues.',
            'fix' => 'utiliser des dates claires et homogènes',
            'findings' => [
                'ok' => ':count périodes, toutes au même format (:style).',
                'no_experience_section' => 'Pas de section expérience, donc aucune date à vérifier.',
                'too_few' => '{0} Aucune période trouvée dans votre expérience.|{1} Une seule période trouvée dans votre expérience.|[2,*] Seulement :count périodes trouvées dans votre expérience.',
                'mixed_styles' => 'Vos périodes utilisent des formats différents (:styles).',
            ],
            'actions' => [
                'no_experience_section' => 'Ajoutez une section expérience avec une période pour chaque poste.',
                'too_few' => 'Indiquez une date de début et de fin pour chaque poste, par exemple « mars 2022 – aujourd\'hui ».',
                'mixed_styles' => 'Écrivez toutes les dates au même format, par exemple « mars 2022 – aujourd\'hui ».',
            ],
        ],
        'action_verbs' => [
            'title' => 'Verbes d\'action',
            'suggestion' => 'Commencez vos puces par un verbe d\'action',
            'why' => 'Une puce qui commence par un verbe fort se lit comme une réalisation, pour les recruteurs comme pour le classement des ATS.',
            'fix' => 'commencer vos puces par un verbe d\'action',
            'findings' => [
                'ok' => ':count puces sur :total commencent par un verbe ou un nom d\'action.',
                'weak_start' => 'Seulement :count puces sur :total commencent par un verbe ou un nom d\'action (60 % requis).',
                'no_bullets' => 'Aucune puce trouvée dans votre expérience.',
            ],
            'actions' => [
                'weak_start' => 'Commencez chaque puce par un verbe comme « Développé », « Piloté », « Réduit » ou « Lancé », ou par un nom d\'action comme « Mise en place de… ».',
                'no_bullets' => 'Décrivez chaque poste en 2 à 5 puces commençant par « • » ou « - ».',
            ],
        ],
        'quantified_results' => [
            'title' => 'Résultats chiffrés',
            'suggestion' => 'Chiffrez vos résultats',
            'why' => 'Les chiffres (pourcentages, montants, volumes) rendent vos résultats concrets, et les recruteurs les cherchent.',
            'fix' => 'chiffrer vos résultats',
            'findings' => [
                'ok' => ':count puces contiennent un chiffre.',
                'too_few' => '{0} Aucune puce ne contient de chiffre, de pourcentage ou de montant (2 requises).|{1} Une seule puce contient un chiffre, un pourcentage ou un montant (2 requises).|[2,*] Seulement :count puces contiennent un chiffre, un pourcentage ou un montant (2 requises).',
                'no_bullets' => 'Aucune puce trouvée dans votre expérience.',
            ],
            'actions' => [
                'too_few' => 'Ajoutez un résultat mesurable à au moins deux puces, par exemple « temps de chargement réduit de 40 % » ou « 120 tickets traités par semaine ».',
                'no_bullets' => 'Décrivez chaque poste en 2 à 5 puces, avec des résultats mesurables.',
            ],
        ],
        'length' => [
            'title' => 'Longueur',
            'suggestion' => 'Ajustez la longueur',
            'why' => 'Un CV trop court paraît léger, un CV trop long noie l\'essentiel. 250 à 1 000 mots conviennent à la plupart des postes.',
            'fix' => 'ajuster la longueur',
            'findings' => [
                'ok' => ':words mots.',
                'too_short' => ':words mots (au moins :min recommandés).',
                'too_long' => ':words mots (au plus :max recommandés).',
            ],
            'actions' => [
                'too_short' => 'Détaillez vos postes : périmètre, outils utilisés et résultats.',
                'too_long' => 'Retirez les postes anciens ou moins pertinents, et limitez chaque puce à une ou deux lignes.',
            ],
        ],
        'no_duplicates' => [
            'title' => 'Pas de puces en double',
            'suggestion' => 'Supprimez les puces en double',
            'why' => 'Les puces répétées prennent de la place et donnent une impression de négligence.',
            'fix' => 'supprimer les puces en double',
            'findings' => [
                'ok' => 'Aucune puce en double.',
                'duplicates' => '{1} Une puce apparaît plusieurs fois.|[2,*] :count puces apparaissent plusieurs fois.',
            ],
            'actions' => [
                'duplicates' => 'Gardez chaque réalisation une seule fois, et réécrivez les puces répétées pour montrer des résultats différents.',
            ],
        ],
        'keyword_coverage' => [
            'title' => 'Mots-clés de l\'offre',
            'suggestion' => 'Couvrez davantage de mots-clés de l\'offre',
            'why' => 'Les ATS classent les CV selon le nombre de mots-clés de l\'offre qu\'ils contiennent.',
            'fix' => 'couvrir davantage de mots-clés de l\'offre',
            'findings' => [
                'complete' => 'Les :total mots-clés de l\'offre apparaissent dans votre CV.',
                'partial' => ':matched mots-clés de l\'offre sur :total apparaissent dans votre CV (couverture pondérée :percent %).',
                'insufficient_job_description' => 'Non noté : trop peu de mots-clés ont été trouvés dans l\'offre.',
            ],
            'actions' => [
                'partial' => 'Ajoutez les mots-clés manquants qui vous correspondent vraiment ; voir les suggestions de mots-clés.',
            ],
        ],
    ],

    'unverified' => [
        'not_inspected' => 'Non vérifié : un texte collé n\'a pas de mise en page. Importez le fichier pour le vérifier.',
        'low_confidence' => 'Non vérifié : la détection n\'était pas assez fiable sur ce fichier.',
        'no_text' => 'Non vérifié : aucun texte n\'a pu être lu.',
    ],

    'keywords' => [
        'keyword_missing' => [
            'required' => [
                'title' => 'Mot-clé requis manquant : :term',
                'detail' => 'L\'offre demande « :term », et ce terme n\'apparaît pas dans votre CV.',
                'action' => 'Si vous avez une réelle expérience de :term, ajoutez-la dans une puce pertinente ou dans vos compétences. N\'ajoutez pas de compétences que vous n\'avez pas.',
            ],
            'preferred' => [
                'title' => 'Mot-clé souhaité manquant : :term',
                'detail' => 'L\'offre cite « :term » comme un atout, et ce terme n\'apparaît pas dans votre CV.',
                'action' => 'Si vous avez une réelle expérience de :term, ajoutez-la dans une puce pertinente ou dans vos compétences. N\'ajoutez pas de compétences que vous n\'avez pas.',
            ],
        ],
        'keyword_skills_only' => [
            'title' => 'Montrez :term dans votre expérience',
            'detail' => '« :term » apparaît uniquement dans votre liste de compétences. Les recruteurs accordent plus de poids à une compétence montrée dans un poste réel.',
            'action' => 'Mentionnez :term dans une puce d\'expérience où vous l\'avez utilisé, si c\'est vrai.',
        ],
        'keyword_stuffing' => [
            'title' => ':term est répété :count fois',
            'detail' => 'Répéter un mot-clé n\'augmente pas votre score et peut donner au recruteur une impression de bourrage de mots-clés.',
            'action' => 'Gardez :term là où il montre un usage réel, et supprimez les répétitions.',
        ],
        'insufficient_job_description' => [
            'title' => 'Collez l\'offre d\'emploi complète',
            'detail' => 'Trop peu de mots-clés ont été trouvés dans l\'offre, la couverture des mots-clés n\'a donc pas été notée.',
            'action' => 'Collez toute l\'annonce, y compris le profil recherché et les atouts.',
        ],
    ],

    'caps' => [
        'major_format_issue' => 'Un problème de mise en page important (colonnes, tableaux ou grandes images) limite le score à 84.',
        'no_email' => 'Aucune adresse e-mail n\'a été trouvée, ce qui limite le score à 79.',
        'no_experience' => 'Aucune section expérience n\'a été trouvée, ce qui limite le score à 74.',
    ],

    'summary' => [
        'unreadable' => 'Aucun texte n\'a pu être lu dans ce fichier, il ne peut donc pas être noté.',
        'insufficient_text' => 'Seulement :words mots ont pu être lus, c\'est trop peu pour noter ce CV.',
        'top_gain' => '{1} :verdict (:score/100) ; le gain le plus important : :fix (+1 point).|[2,*] :verdict (:score/100) ; le gain le plus important : :fix (+:count points).',
        'no_gain' => ':verdict (:score/100) ; aucune correction seule ne l\'augmente, mais les suggestions ci-dessous restent utiles.',
        'all_passed' => ':verdict (:score/100) ; tous les contrôles sont réussis.',
        'verdicts' => [
            'strong' => 'Très bon score',
            'good' => 'Bon score',
            'needs_work' => 'Votre CV est à améliorer',
            'poor' => 'Score faible',
        ],
        'fix_keyword' => 'ajouter « :term » si cela vous correspond',
    ],

    'limitations' => [
        'estimate' => 'Ceci est une estimation de la qualité du document et de la couverture des mots-clés, pas le résultat de l\'ATS d\'un employeur.',
        'pdf_heuristics' => 'La détection de la mise en page dans les PDF est approximative ; chaque constat indique son niveau de confiance.',
        'no_ocr' => 'Les documents scannés ne sont pas lus (pas d\'OCR).',
        'pasted_text' => 'Un texte collé n\'a pas de mise en page : les contrôles de format n\'ont pas été faits. Importez le fichier pour les obtenir.',
        'other_language' => 'Votre CV n\'est ni en français ni en anglais : les mots-clés ne sont reconnus que s\'ils sont écrits de la même façon.',
    ],

    'formatting' => [
        'common' => [
            'not_inspected' => 'Un texte collé n\'a pas de mise en page à analyser.',
            'not_applicable_pdf' => 'Ne s\'applique pas aux fichiers PDF.',
        ],
        'columns' => [
            'found' => 'Le texte est disposé sur plusieurs colonnes.',
            'found_pages' => 'Le texte est disposé sur deux colonnes à la page :pages.',
        ],
        'tables' => [
            'found' => '{0} Des tableaux contiennent du texte.|{1} Un tableau contient du texte.|[2,*] :count tableaux contiennent du texte.',
            'possible' => 'Texte aligné qui ressemble à un tableau à la page :pages (confiance faible).',
        ],
        'images' => [
            'found' => '{1} Une image.|[2,*] :count images.',
            'found_area' => '{1} Une image, environ :largest_area_pct % de la page.|[2,*] :count images, la plus grande couvre environ :largest_area_pct % de la page.',
        ],
        'text_boxes' => [
            'found' => '{0} Des zones de texte contiennent du texte.|{1} Une zone de texte contient du texte.|[2,*] :count zones de texte contiennent du texte.',
        ],
        'header_footer' => [
            'found' => 'Du texte figure dans l\'en-tête ou le pied de page.',
            'contact_only' => 'Les coordonnées apparaissent uniquement dans l\'en-tête ou le pied de page.',
            'single_page' => 'PDF d\'une seule page : aucun en-tête ou pied de page répété à détecter.',
        ],
        'glyph_issues' => [
            'found' => 'Icônes, caractères illisibles ou titres aux lettres espacées détectés.',
        ],
    ],

    'date_styles' => [
        'month' => 'mars 2022',
        'numeric' => '03/2022',
        'year' => '2022',
    ],
];
