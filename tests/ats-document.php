<?php
require __DIR__.'/../app/Services/ResumeLanguage.php';
require __DIR__.'/../app/Services/AtsDocumentReview.php';
use App\Services\AtsDocumentReview;
function assertDocument($condition, $message): void {if (!$condition) throw new RuntimeException($message);}
$engine=new AtsDocumentReview();
$cv="Profile\nDeveloper with several years of collaboration on client tools and software projects, supporting teams with careful implementation and practical improvements in customer workflows.\ncontact@example.com\nExperience\n2024-2025 ACME\n- Developed a client dashboard to improve onboarding for 50 customers.\n- Implemented data export to reduce processing time for clients and project managers.\n- Designed a support workflow to improve response times for 30 users.\nEducation\nComputer science degree at a university in 2023.\nSkills\nReact Laravel PHP MySQL and project coordination.\n";
$ideal=$engine->analyze($cv);
assertDocument($ideal['max_score']===100,'Total weight must be 100');
assertDocument($ideal['raw_score']===100,'Fully evidenced fixture should earn every check');
assertDocument($ideal['issues']===0,'Fully evidenced fixture should have no issues');
assertDocument($ideal['score']===94,'Top band capped at 94');
$missingEmail=$engine->analyze(str_replace('contact@example.com','',$cv));
assertDocument($missingEmail['score']<=69,'No email must cap the score');
$missingExperience=$engine->analyze(str_replace('Experience','Other', $cv));
assertDocument($missingExperience['score']<=65,'Missing experience heading must cap the score');
$noEducation=$engine->analyze(str_replace('Education','Other', $cv));
assertDocument($noEducation['score']<=89 && $noEducation['issues']>0,'Any failed check prevents 90+');
$brief=$engine->analyze('Experience'."\nShort sentence.");
assertDocument($brief['score']===null,'Short fragments are not scored');
assertDocument(count($brief['priorities'])>0,'Short text still returns actionable findings');
$repeat=$engine->analyze($cv);
assertDocument($ideal===$repeat,'Same CV must always produce the same report');
$categoryChecks=array_merge(...array_column($ideal['categories'],'checks'));
assertDocument(count($categoryChecks)>=12 && !array_filter($categoryChecks,fn($c)=>$c['finding']===''||$c['action']===''),'Every check has a finding and action');
$french=$engine->analyze("Profil\nDéveloppeur ayant réalisé plusieurs projets pour des clients avec une équipe professionnelle.\ncontact@example.com\nExpérience professionnelle\n2024-2025 ACME\n- Développé un portail pour améliorer le suivi de 50 clients dans l'entreprise.\n- Réalisé des améliorations pour faciliter l'accès des utilisateurs aux documents.\n- Conçu une interface pour réduire les erreurs de saisie des projets.\nFormation\nDiplôme en développement informatique dans une école de formation.\nCompétences\nReact Laravel PHP MySQL et travail en équipe.");
assertDocument($french['max_score']===100 && $french['score']!==null,'French headings are assessed');
assertDocument(str_contains($french['categories'][0]['title'],'Lecture'),'French document check copy');
echo "ATS document review checks passed.\n";
