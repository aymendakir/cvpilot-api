<?php

/*
 * BASE-FR (SPEC-ats.md §8, fixture F11): the same fictional profile in French, headings
 * "Expérience professionnelle", "Formation", "Compétences". Facts checked by FixturesTest:
 *   present: PHP, Symfony, MySQL, Docker, "gestion de projets", "tests unitaires"
 *   absent:  Vue (any form), so the only F11 gap is the preferred keyword Vue.js
 * Experience has 10 bullets, all starting with an action verb; exactly 4 contain a number.
 */

return [
    'name' => 'Samir Benali',
    'title' => 'Développeur backend',
    'email' => 'samir.benali@example.com',
    'email_label' => 'E-mail',
    'phone' => '+212 600 123 456',
    'phone_label' => 'Téléphone',
    'location' => 'Rabat, Maroc',
    'summary_heading' => 'Profil',
    'summary' => 'Développeur backend avec sept ans d’expérience dans la création d’applications PHP fiables pour de petites équipes produit. J’attache de l’importance à un code lisible, à une conception soignée des bases de données et aux tests automatisés. J’aime travailler avec les designers, le support et les responsables produit pour transformer les retours des clients en fonctionnalités réellement utilisées.',
    'experience_heading' => 'Expérience professionnelle',
    'experience' => [
        [
            'role' => 'Développeur backend',
            'company' => 'Atlas Commerce',
            'place' => 'Rabat',
            'dates' => 'mars 2022 – aujourd’hui',
            'about' => 'Distributeur en ligne d’articles pour la maison au Maroc, via une boutique web, une application mobile et des magasins partenaires. J’ai rejoint l’équipe de huit personnes comme deuxième développeur backend.',
            'bullets' => [
                'Développé une API en Symfony pour l’application mobile et le portail partenaires, avec des versions d’API et des messages d’erreur clairs pour les équipes clientes.',
                'Réduit de 40 % le temps de réponse moyen du paiement en réécrivant des requêtes MySQL lentes et en ajoutant les index manquants.',
                'Mis en place l’intégration continue avec GitHub Actions pour que chaque demande de fusion lance les tests unitaires et l’analyse statique.',
                'Migré l’environnement de développement vers Docker, ce qui a réduit l’installation des nouveaux arrivants de 3 jours à une matinée.',
            ],
        ],
        [
            'role' => 'Développeur PHP',
            'company' => 'Medina Digital',
            'place' => 'Casablanca',
            'dates' => 'juin 2019 – février 2022',
            'about' => 'Agence qui conçoit des outils de gestion pour des cliniques, des écoles et des entreprises de services, avec des équipes de deux ou trois développeurs par projet.',
            'bullets' => [
                'Conçu les modules de réservation et de facturation d’une plateforme de gestion utilisée chaque jour par des médecins et leurs secrétaires.',
                'Rédigé des tests unitaires pour la facturation et porté leur couverture de 35 % à 80 %, ce qui a rendu les livraisons mensuelles plus sereines.',
                'Assuré la gestion de projets de bout en bout pour trois clients, du recueil des besoins jusqu’à la mise en production.',
            ],
        ],
        [
            'role' => 'Développeur web junior',
            'company' => 'Sahara Studio',
            'place' => 'Marrakech',
            'dates' => 'septembre 2017 – mai 2019',
            'about' => 'Petit studio qui conçoit et héberge des sites pour des hôtels, des restaurants et des agences de voyage, au contact direct des clients.',
            'bullets' => [
                'Maintenu les sites de clients hôteliers et corrigé les anomalies signalées dans les délais convenus avec chaque client.',
                'Automatisé les exports hebdomadaires de rapports et fait gagner 6 heures de travail manuel par semaine aux chargés de compte.',
                'Documenté les étapes de déploiement, les accès serveur et les sauvegardes pour que les autres développeurs puissent livrer en toute sécurité.',
            ],
        ],
    ],
    'education_heading' => 'Formation',
    'education' => [
        [
            'degree' => 'Licence en informatique',
            'school' => 'Université Mohammed V, Rabat',
            'dates' => 'septembre 2014 – juin 2017',
            'detail' => 'Projet de fin d’études : un système de réservation de salles pour la bibliothèque de la faculté, en PHP et MySQL, utilisé par les étudiants pendant ma dernière année.',
        ],
    ],
    'skills_heading' => 'Compétences',
    'skills' => [
        'PHP',
        'Symfony',
        'MySQL',
        'Docker',
        'Tests unitaires',
        'GitHub Actions',
        'HTML et CSS',
        'Serveurs Linux',
        'Nginx',
        'Composer',
        'Revue de code',
        'Gestion de projets',
        'Méthodes agiles',
        'Arabe (langue maternelle)',
        'Français (courant)',
        'Anglais (courant)',
    ],
];
