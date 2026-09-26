<?php
require __DIR__.'/../app/Services/ResumeReview.php';
use App\Services\ResumeReview;
$cv="Experience\nBuilt 3 applications.\nManaged deployments.";
function expect($condition) { if (!$condition) throw new RuntimeException('Review validation failed'); }
$valid=['summary'=>'Clear experience.', 'suggestions'=>[['original'=>'Built 3 applications.', 'replacement'=>'Delivered 3 applications.', 'reason'=>'Clearer verb.', 'category'=>'Clarity']]];
expect(count(ResumeReview::parse(json_encode($valid),$cv)['suggestions'])===1);
$valid['suggestions'][0]['replacement']='Delivered 30 applications.';
expect(count(ResumeReview::parse(json_encode($valid),$cv)['suggestions'])===0);
$valid['suggestions'][0]['original']='Invented source line';
expect(count(ResumeReview::parse(json_encode($valid),$cv)['suggestions'])===0);
expect(ResumeReview::parse('not json',$cv)===null);
expect(ResumeReview::parse('{"summary":[],"suggestions":[]}',$cv)===null);
echo "Resume review validation passed.\n";
