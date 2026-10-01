<?php

require __DIR__.'/../../app/Services/ResumeLanguage.php';
use App\Services\ResumeLanguage;

function checkLanguage(bool $condition): void
{
    if (! $condition) {
        throw new RuntimeException('Resume language check failed');
    }
}
checkLanguage(ResumeLanguage::detect('Expérience professionnelle : développeur web. Compétences, formation, projets chez une entreprise.') === 'French');
checkLanguage(ResumeLanguage::detect('Work experience and education. I developed projects with a professional team and managed deployments.') === 'English');
checkLanguage(ResumeLanguage::detect('Experiencia profesional en proyectos. Formación de desarrollador y trabajo con una empresa.') === 'Spanish');
checkLanguage(ResumeLanguage::detect('الخبرة المهنية والمهارات والتعليم والعمل مع الشركات والمشاريع والمساهمة في تطوير التطبيقات') === 'Arabic');
checkLanguage(ResumeLanguage::detect('React Laravel AWS') === null);
checkLanguage(ResumeLanguage::clearlyEnglish('Your work experience is clear, but you should improve this line with concrete results.'));
checkLanguage(! ResumeLanguage::clearlyEnglish('Votre expérience présente des projets et des compétences concrètes.'));
echo "Resume language checks passed.\n";
