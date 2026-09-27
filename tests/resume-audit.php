<?php
require __DIR__.'/../app/Services/ResumeReview.php';
require __DIR__.'/../app/Services/ResumeAudit.php';
use App\Services\ResumeAudit;
function expectAudit($ok) { if (!$ok) throw new RuntimeException('Audit validation failed'); }
$cv="Experience\nBuilt 3 applications.\n".str_repeat('Maintained useful client portal features with the development team. ',12);
$data=['summary'=>'Clear contributions.','suggestions'=>[['original'=>'Built 3 applications.','replacement'=>'Delivered 3 applications.','reason'=>'Clear wording.','category'=>'Clarity']], 'strengths'=>[['title'=>'Delivery','evidence'=>'Built 3 applications.']], 'priorities'=>[], 'rubric'=>[], 'requirements'=>[['requirement'=>'React required','status'=>'supported','evidence'=>'Built 3 applications.']]];
foreach(['clarity','specificity','relevance','organization'] as $key) $data['rubric'][$key]=['level'=>3,'reason'=>'Clear evidence','evidence'=>'Built 3 applications.'];
$read=fn($d,$text=null,$job='React required')=>ResumeAudit::parse(json_encode($d),$text ?? $cv,$job);
expectAudit($read($data)['score']===75);
$spaced=$data;$spaced['rubric']['clarity']['evidence']='Maintained useful client portal features  with the development team.';
expectAudit($read($spaced)['score']===75);
$shorter='Experience'."\nBuilt 3 applications.\n".str_repeat('Maintained useful client portal features with the development team. ',7);
expectAudit($read($data,$shorter)['score']===75);
expectAudit(count($read($data)['suggestions'])===1);
expectAudit($read($data,'Built 3 applications.')['score']===null);
expectAudit($read($data,$cv,'')['requirements']===[]);
$bad=$data;$bad['rubric']['clarity']['evidence']='Not in this CV';expectAudit($read($bad)['score']===null);
$bad=$data;$bad['rubric']['clarity']['level']=8;expectAudit($read($bad)['score']===null);
$bad=$data;$bad['strengths'][0]['evidence']='Made up';expectAudit($read($bad)['strengths']===[]);
$bad=$data;$bad['suggestions'][0]['replacement']='Delivered 30 applications.';expectAudit($read($bad)['suggestions']===[]);
$bad=$data;$bad['requirements'][0]['requirement']='AWS required';expectAudit($read($bad)['requirements']===[]);
expectAudit(ResumeAudit::parse('invalid JSON',$cv)===null);
echo "12 ResumeAudit checks passed.\n";
