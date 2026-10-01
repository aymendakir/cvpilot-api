<?php

/*
 * BASE-EN (SPEC-ats.md §8): a fictional PHP/Laravel developer. Every English variant in build.php is
 * derived from this array, so these facts hold for all of them (FixturesTest checks them):
 *   present: PHP, Laravel, MySQL, Docker, PHPUnit, "RESTful API", "GitHub", "continuous integration", "Vue"
 *   absent:  Redis, AWS, Kubernetes, GraphQL, Terraform, Symfony, and the standalone tokens
 *            "Git", "REST", "CI/CD", "Vue.js" (so §8.2's synonym matches stay synonym matches)
 * Experience has 10 bullets, all starting with an action verb; exactly 4 contain a number.
 * "Laravel" appears at most 10 times (the stuffing variant raises it to 15).
 */

return [
    'name' => 'Samir Benali',
    'title' => 'Backend Developer',
    'email' => 'samir.benali@example.com',
    'email_label' => 'Email',
    'phone_label' => 'Phone',
    'phone' => '+212 600 123 456',
    'location' => 'Rabat, Morocco',
    'summary_heading' => 'Profile',
    'summary' => 'Backend developer with seven years of experience building reliable PHP and Laravel applications for small product teams. I care about readable code, careful database design and automated tests, and I like working closely with designers, support staff and product owners to turn customer feedback into features that people actually use. I am looking for a role where I can keep improving an existing product and help junior developers grow.',
    'experience_heading' => 'Work Experience',
    'experience' => [
        [
            'role' => 'Backend Developer',
            'company' => 'Atlas Commerce',
            'place' => 'Rabat',
            'dates' => 'Mar 2022 – Present',
            'about' => 'Online retailer selling home goods across Morocco through a web shop, a mobile app and partner stores. I joined as the second backend developer in a team of eight.',
            'bullets' => [
                'Built a RESTful API in Laravel that serves the mobile shopping app and the partner portal, with versioned endpoints and clear error messages for the client teams.',
                'Reduced average checkout response time by 40% by rewriting slow MySQL queries, adding missing indexes and caching the product catalogue between requests.',
                'Introduced continuous integration with GitHub Actions so that every pull request runs PHPUnit, coding style checks and static analysis before review.',
                'Moved local development to Docker, which removed recurring setup problems and cut the setup time for new team members from 3 days to one morning.',
            ],
        ],
        [
            'role' => 'PHP Developer',
            'company' => 'Medina Digital',
            'place' => 'Casablanca',
            'dates' => 'Jun 2019 – Feb 2022',
            'about' => 'Software agency building management tools for clinics, schools and local service companies. Most projects were delivered by teams of two or three developers.',
            'bullets' => [
                'Developed the booking and invoicing modules of a clinic management platform used daily by private doctors and their reception staff.',
                'Wrote PHPUnit tests for the billing code and raised its coverage from 35% to 80%, which made monthly releases much calmer for the whole team.',
                'Created admin screens in Vue that let support agents refund orders, correct invoices and update customer records without asking a developer.',
            ],
        ],
        [
            'role' => 'Junior Web Developer',
            'company' => 'Sahara Studio',
            'place' => 'Marrakesh',
            'dates' => 'Sep 2017 – May 2019',
            'about' => 'Small studio designing and hosting websites for hotels, restaurants and tour operators, where I learned to support real customers directly.',
            'bullets' => [
                'Maintained client websites for hotels and restaurants and fixed reported bugs within the support deadlines agreed with each customer.',
                'Automated the weekly report exports for account managers, saving them 6 hours of manual spreadsheet work every week.',
                'Documented deployment steps, server access and database backups so that other developers could release changes safely when I was away.',
            ],
        ],
    ],
    'education_heading' => 'Education',
    'education' => [
        [
            'degree' => 'Bachelor of Science in Computer Science',
            'school' => 'Mohammed V University, Rabat',
            'dates' => 'Sep 2014 – Jun 2017',
            'detail' => 'Final project: a room reservation system for the faculty library, built with PHP and MySQL and used by students during my final year.',
        ],
    ],
    'skills_heading' => 'Skills',
    'skills' => [
        'PHP',
        'Laravel',
        'MySQL',
        'PHPUnit',
        'Docker',
        'GitHub Actions',
        'Vue',
        'HTML and CSS',
        'Linux servers',
        'Nginx',
        'Composer',
        'Code review',
        'Agile teamwork',
        'English (fluent)',
        'French (fluent)',
        'Arabic (native)',
    ],
];
