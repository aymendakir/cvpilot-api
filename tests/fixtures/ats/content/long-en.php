<?php

/*
 * F12 (SPEC-ats.md §8.1): BASE-EN stretched to ~1 400 words so that only the `length` check fails.
 * Every added bullet starts with an action verb and none contains a number, so `action_verbs`,
 * `quantified_results` and `dates` keep passing; no new keyword from §8.2 is introduced.
 */

$c = require __DIR__.'/base-en.php';

$c['summary'] .= ' Over the years I have worked on online shops, booking tools, internal dashboards and public websites. I enjoy the unglamorous parts of the job as much as the new features: reading old code before changing it, writing down how things work, answering questions from support colleagues and making releases boring in the best possible way. Outside of client work I read about database internals, follow the PHP community closely and try new tools on small side projects before suggesting them to a team.';

foreach ($c['experience'] as $i => $job) {
    $c['experience'][$i]['about'] .= ' My day-to-day work combined feature development, code review, production support and regular conversations with the people who used the software, which taught me to explain technical trade-offs in plain language and to estimate work honestly.';
}

$c['experience'] = array_merge($c['experience'], [
    [
        'role' => 'Teaching Assistant',
        'company' => 'Mohammed V University',
        'place' => 'Rabat',
        'dates' => 'Sep 2015 – Jun 2016',
        'about' => 'Part-time position in the computer science department during my second year of studies, supporting first-year students in their introductory programming and web courses. The role gave me my first real experience of planning work, keeping promises to other people and learning quickly from more experienced colleagues around me.',
        'bullets' => [
            'Led weekly practical sessions on HTML, CSS and basic PHP for groups of first-year students, explaining each exercise step by step and answering questions after class.',
            'Prepared short exercises and correction sheets with the lecturers, focusing on the mistakes students made most often in their assignments and exams.',
            'Helped students set up their development environments on very different laptops, which taught me patience and the value of clear written instructions.',
            'Collected anonymous feedback at the end of each semester and shared it with the teaching team so that the next course could address the main difficulties earlier.',
        ],
    ],
    [
        'role' => 'Volunteer Developer',
        'company' => 'Code Club Rabat',
        'place' => 'Rabat',
        'dates' => 'Oct 2018 – Jun 2020',
        'about' => 'Community association that runs free evening workshops where teenagers and adults learn to build their first websites with the help of volunteer developers. The role gave me my first real experience of planning work, keeping promises to other people and learning quickly from more experienced colleagues around me.',
        'bullets' => [
            'Mentored beginners during evening workshops, reviewed their small projects and encouraged them to publish their first websites and share them with their families.',
            'Rebuilt the association website so that volunteers could publish workshop dates, registration forms and photos without asking a developer for help.',
            'Organised a series of introductory talks on databases and testing, and wrote simple handouts that participants could keep and reuse after the sessions.',
            'Coordinated a small group of volunteers to answer questions in the online forum of the association, sharing the effort so that nobody was overwhelmed.',
        ],
    ],
    [
        'role' => 'Web Development Intern',
        'company' => 'Oasis Travel',
        'place' => 'Agadir',
        'dates' => 'Feb 2017 – Jul 2017',
        'about' => 'Regional travel agency selling excursions and hotel packages online and through a small network of local offices. I joined during my final semester and worked closely with the only in-house developer. The role gave me my first real experience of planning work, keeping promises to other people and learning quickly from more experienced colleagues around me.',
        'bullets' => [
            'Implemented a simple excursion booking form with server-side validation and confirmation emails that customers could print and bring to the meeting point.',
            'Improved the search page of the public website so that visitors could filter excursions by city, duration, language and type of activity without reloading the page.',
            'Prepared the content migration from the old website by cleaning product descriptions, merging duplicates and fixing broken links reported by the sales team.',
            'Supported the office staff during the launch of the new website, collected their feedback in a shared document and turned the most important requests into small fixes.',
        ],
    ],
    [
        'role' => 'Freelance Web Developer',
        'company' => 'Self-employed',
        'place' => 'Rabat',
        'dates' => 'Jan 2016 – Jan 2017',
        'about' => 'Small websites and maintenance work for local shops, associations and independent professionals, usually found through recommendations from earlier customers. The role gave me my first real experience of planning work, keeping promises to other people and learning quickly from more experienced colleagues around me.',
        'bullets' => [
            'Designed and launched simple showcase websites for a bakery, a language school and a carpentry workshop, including contact forms and basic search engine settings.',
            'Maintained the membership website of a local sports association and helped its volunteers update the news section and the training calendar on their own.',
            'Advised customers on hosting, domain names and backups, and wrote short guides so they could keep their websites running without depending on me.',
            'Organised my work with written quotes, clear delivery dates and a short handover meeting at the end of every project, which brought most of my later customers.',
        ],
    ],
]);

$c['education'][] = [
    'degree' => 'Online courses in software design and databases',
    'school' => 'Various providers',
    'dates' => 'Sep 2018 – Jun 2021',
    'detail' => 'Completed courses on database design, clean architecture, automated testing and accessibility, and applied the exercises to small personal projects that I keep on my public profile. These courses helped me write clearer code, review the work of colleagues with more confidence and explain design choices to non-technical people. I still take one short course a year and keep notes on what I learn so that I can share it with my colleagues during our internal knowledge sessions.',
];

return $c;
