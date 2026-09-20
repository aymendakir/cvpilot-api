<?php
namespace App\Services;
class AtsScorer{
 private array $stop=['with','that','this','your','from','have','will','work','team','and','the','our','are','experience','years','skills','role','position','candidate','company','dans','pour','avec','vous','nous','une','des','les','sur','aux','de','la','le','un','et','en','to','of','in','on','for','is','be','as','at','or','it','we','you'];
 function score(string $cv,string $job):array{
  $tokens=fn($v)=>array_values(array_unique(array_filter(preg_split('/[^\pL\pN+#.\/-]+/u',mb_strtolower($v)),fn($x)=>mb_strlen($x)>=3&&!in_array($x,$this->stop,true))));
  $jobTerms=$tokens($job);$cvTerms=array_flip($tokens($cv));$matched=array_values(array_filter($jobTerms,fn($x)=>isset($cvTerms[$x])));$missing=array_values(array_diff($jobTerms,$matched));
  $keyword=(int)round((count($matched)/max(1,count($jobTerms)))*45);$sections=0;foreach(['/experience|employment|expérience/i','/skills|compétences|technologies/i','/education|formation|diplôme|degree/i','/[\w.+-]+@[\w.-]+\.[a-z]{2,}|(?:\+?\d[\s.-]?){8,}/i'] as $p)$sections+=preg_match($p,$cv)?1:0;
  $lines=array_filter(array_map('trim',preg_split('/\R/',$cv)));$long=count(array_filter($lines,fn($l)=>mb_strlen($l)>170));$words=str_word_count(strip_tags($cv));$readability=max(4,min(20,20-$long*3-($words<180?5:0)-($words>1100?4:0)));
  preg_match_all('/\b(built|created|developed|designed|implemented|launched|improved|increased|reduced|optimized|automated|managed|led|delivered|integrated|deployed|développé|créé|conçu|amélioré|optimisé|géré|réalisé)\b/iu',$cv,$verbs);preg_match_all('/\b\d+(?:[.,]\d+)?\s*(?:%|k|m|hours?|days?|users?|clients?|projects?|ans?|mois)?\b/iu',$cv,$numbers);$impact=min(15,(int)round(min(count($verbs[0]),6)*1.5+min(count($numbers[0]),6)));
  $suggestions=[];foreach($lines as $i=>$line){if(preg_match('/^(?:[-•]\s*)?(responsible for|responsable de|worked on|travail sur)/iu',$line))$suggestions[]=['line'=>$i+1,'reason'=>'Start with a strong action verb.','original'=>$line];elseif(mb_strlen($line)>170)$suggestions[]=['line'=>$i+1,'reason'=>'Split this long line into shorter bullets.','original'=>$line];if(count($suggestions)>=8)break;}
  return ['score'=>min(100,$keyword+$sections*5+$readability+$impact),'breakdown'=>['keywords'=>$keyword,'sections'=>$sections*5,'readability'=>$readability,'impact'=>$impact],'matched_keywords'=>array_slice($matched,0,20),'missing_keywords'=>array_slice($missing,0,20),'suggestions'=>$suggestions];
 }
}
