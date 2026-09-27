<?php
namespace App\Services;

/** Observable text checks; this cannot emulate any employer's proprietary ATS. */
class AtsDocumentReview
{
    public const VERSION = 'document-3';

    public function analyze(string $text, ?string $fileName = null): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn($line) => $line !== ''));
        $wordCount = preg_match_all('/[\p{L}\p{N}]+/u', $text);
        $french = ResumeLanguage::detect($text) === 'French';
        $clean = static fn(string $line): string => mb_strtolower(preg_replace('/\s+/u', ' ', trim($line)), 'UTF-8');
        $categories = [];
        $add = static function (string $id, string $title, int $max, float $fraction, array $evidence, string $finding, string $action) use (&$categories): void {
            $earned = (int) round(max(0, min(1, $fraction)) * $max);
            $categories[count($categories)-1]['checks'][] = [
                'id'=>$id, 'title'=>$title, 'status'=>$earned===$max?'pass':'review',
                'earned'=>$earned, 'max'=>$max, 'finding'=>$finding, 'action'=>$action,
                'evidence'=>array_slice(array_values(array_filter($evidence,static fn($line)=>trim($line)!=='')),0,2),
            ];
        };
        $group = static function (string $id, string $title, string $description) use (&$categories): void {
            $categories[]=['id'=>$id,'title'=>$title,'description'=>$description,'checks'=>[]];
        };
        $headingType = static function (string $line) use ($clean): ?string {
            $line = $clean($line);
            $line = trim($line, " \t\n\r\0\x0B:-–—|");
            if (mb_strlen($line)>55) return null;
            if (preg_match('/^(?:(?:professional|work|relevant)\s+)?(?:experience|experiences|employment|work history|projects|expérience(?:s)?(?: professionnelle(?:s)?)?|parcours professionnel|projets?|stages?)$/u',$line)) return 'experience';
            if (preg_match('/^(?:education|éducation|formation(?:s)?|études|academic background|diplômes?)$/u',$line)) return 'education';
            if (preg_match('/^(?:(?:technical|professional)\s+)?(?:skills|competencies|technologies|compétences(?: techniques)?|outils)$/u',$line)) return 'skills';
            if (preg_match('/^(?:summary|profile|profil|résumé|languages|langues|certifications|contact|about me)$/u',$line)) return 'other';
            return null;
        };
        $sections=['experience'=>[], 'education'=>[], 'skills'=>[]];$headings=[];$section=null;
        foreach ($lines as $line) {
            $type=$headingType($line);
            if ($type) {$section=$type==='other'?null:$type;$headings[$type]=$line;continue;}
            if ($section) $sections[$section][]=$line;
        }
        $wordCountOf = static fn(string $line): int => preg_match_all('/[\p{L}\p{N}]+/u',$line);
        $contributions=array_values(array_filter($sections['experience'],static fn(string $line):bool => $wordCountOf($line)>=7 && !preg_match('/^[\d\s\/–—.,-]+$/u',$line)));
        $key=static fn(string $line):string => preg_replace('/[^\p{L}\p{N}]+/u',' ',mb_strtolower(preg_replace('/^[-•*·▪–\s]+/u','',$line),'UTF-8'));
        $unique=[];$dupes=[];
        foreach ($contributions as $line) { $k=trim($key($line)); if(isset($unique[$k]))$dupes[]=$line;else $unique[$k]=$line; }
        $unique=array_values($unique);
        $missingChars=array_values(array_filter($lines,static fn(string $line):bool => str_contains($line,"\u{FFFD}")));
        $fragmented=array_values(array_filter($lines, static fn(string $line):bool => preg_match('/^\p{L}$/u',$line)===1));
        $letterSpaced=array_values(array_filter($lines,static fn(string $line):bool => preg_match('/(?:\b\p{L}\s+){5,}\p{L}\b/u',$line)===1));
        $email=array_values(array_filter($lines,static fn(string $line):bool=>preg_match('/[\w.+-]+@[\w.-]+\.[a-z]{2,}/iu',$line)===1));
        $dated=array_values(array_filter($sections['experience'],static fn(string $line):bool=>preg_match('/\b(?:19|20)\d{2}\b/',$line)===1));
        $actions=array_values(array_filter($unique,static fn(string $line):bool=>preg_match('/\b(?:built|developed|designed|implemented|delivered|managed|led|created|improved|reduced|increased|organized|supported|resolved|maintained|tested|prepared|trained|coordinated|automated|développé|développée|conçu|conçue|créé|créée|amélioré|améliorée|réalisé|réalisée|optimisé|optimisée|géré|gérée|coordonné|coordonnée|livré|livrée|piloté|pilotée|formé|formée|développement|création|intégration|conception|collaboration|utilisation|résolution|maintenance|participation)\b/iu',$line)===1));
        $weak=array_values(array_filter($unique,static fn(string $line):bool=>preg_match('/\b(?:responsible for|duties included|in charge of|responsable de|chargé de|chargée de)\b/iu',$line)===1));
        $concise=array_values(array_filter($unique,static fn(string $line):bool=>$wordCountOf($line)<=45));
        $outcomes=array_values(array_filter($unique,static fn(string $line):bool=>preg_match('/\b(?:resulting in|enabled|reduced|increased|improved|saved|to improve|to reduce|to enable|to simplify|permettant|réduit|amélioré|facilité|optimisé|afin de|pour améliorer|pour faciliter)\b|\d+(?:[.,]\d+)?\s*(?:%|clients?|users?|utilisateurs?|projets?|projects?|heures?|hours?)\b/iu',$line)===1));
        $count=count($unique);
        $group('extraction',$french?'Lecture du fichier':'Extracted text',$french?'Qualité du texte extrait; la mise en page visuelle reste à vérifier.':'Text extraction quality; visual layout still needs your review.');
        $add('readable',$french?'Texte exploitable':'Readable text',5,$wordCount>=60?1:$wordCount/60,[], $french?"{$wordCount} mots lisibles détectés.":"{$wordCount} readable words detected.",$french?'Vérifiez le texte extrait : un scan image nécessite un OCR.':'Review extracted text; image scans need OCR.');
        $add('characters',$french?'Caractères lisibles':'Character integrity',5,$missingChars?0:1,$missingChars,$missingChars?($french?'Caractères illisibles trouvés.':'Unreadable replacement characters found.'):($french?'Aucun caractère de remplacement détecté.':'No replacement characters detected.'),$french?'Réexportez le PDF avec une couche de texte.':'Export a text-based PDF.');
        $add('fragmentation',$french?'Ordre de lecture':'Reading continuity',5,count($fragmented)<=2 && !$letterSpaced?1:0,[...$fragmented,...$letterSpaced],$french?count($fragmented).' lettres isolées, '.count($letterSpaced).' ligne(s) avec des mots espacés lettre par lettre.':count($fragmented).' isolated letters, '.count($letterSpaced).' line(s) with letter-spaced words.',$french?'Vérifiez les colonnes et remplacez les titres espacés lettre par lettre par du texte normal.':'Inspect columns and replace letter-spaced headings with normal selectable text.');
        $group('contact',$french?'Coordonnées':'Contact',$french?'Ce que le texte permet de trouver; coordonnées non vérifiées.':'Only observable contact text; delivery is not verified.');
        $add('email',$french?'Adresse e-mail':'Email address',10,$email?1:0,$email,$email?($french?'Adresse détectée.':'Address detected.'):($french?'Aucune adresse e-mail lisible trouvée.':'No readable email found.'),$french?'Ajoutez une adresse e-mail visible dans le CV.':'Add a visible email address.');
        $group('structure',$french?'Structure des rubriques':'Sections',$french?'Une rubrique compte seulement si du contenu lisible la suit.':'A heading counts only with readable content beneath it.');
        $add('experience',$french?'Expérience ou projets':'Experience or projects',10,count($sections['experience'])>=2?1:0,[$headings['experience']??'',...$sections['experience']],isset($headings['experience'])?($french?'Rubrique reconnue; '.count($sections['experience']).' ligne(s) dessous.':'Heading recognized; '.count($sections['experience']).' lines beneath it.'):($french?'Rubrique Expérience / Projets introuvable.':'Experience / Projects heading not recognized.'),$french?'Utilisez une rubrique standard et ajoutez rôles, projets et réalisations.':'Use a standard heading and add roles, projects and contributions.');
        $add('education',$french?'Formation':'Education',5,array_filter($sections['education'],static fn($s)=>$wordCountOf($s)>=3)?1:0,[$headings['education']??'',...$sections['education']],isset($headings['education'])?($french?'Rubrique reconnue; vérification du contenu.':'Heading recognized; checking content.'):($french?'Rubrique Formation introuvable.':'Education / Formation heading not recognized.'),$french?'Indiquez diplôme ou établissement sous Formation.':'Add degree or institution beneath Education.');
        $add('skills',$french?'Compétences':'Skills',5,array_filter($sections['skills'],static fn($s)=>$wordCountOf($s)>=2)?1:0,[$headings['skills']??'',...$sections['skills']],isset($headings['skills'])?($french?'Rubrique reconnue; vérification du contenu.':'Heading recognized; checking content.'):($french?'Rubrique Compétences introuvable.':'Skills / Compétences heading not recognized.'),$french?'Listez seulement les compétences réellement maîtrisées.':'List only skills you actually have.');
        $add('dates',$french?'Dates d’expérience':'Experience dates',5,$dated?1:0,$dated,$dated?($french?'Une année apparaît sous Expérience.':'A year appears under Experience.'):($french?'Aucune année reconnue sous Expérience.':'No year recognized under Experience.'),$french?'Ajoutez une période vérifiable à chaque poste ou projet.':'Add a verifiable period to each role or project.');
        $group('evidence',$french?'Expérience démontrée':'Experience evidence',$french?'Qualité des contributions observables, sans exiger de chiffres inventés.':'Observable contributions; no invented metrics are required.');
        $add('substance',$french?'Contributions distinctes':'Distinct contributions',15,min($count/3,1),$unique,$french?"{$count} contributions distinctes de 7 mots ou plus.":"{$count} distinct contributions of 7+ words.",$french?'Décrivez trois tâches ou réalisations précises.':'Describe three specific responsibilities or outcomes.');
        $add('ownership',$french?'Actions précises':'Clear actions',10,$count?count($actions)/$count:0,$actions,$french?count($actions)." sur {$count} contributions contiennent une action reconnue.":count($actions)." of {$count} contributions contain a recognized action.",$french?'Décrivez votre contribution avec un verbe fidèle à votre rôle.':'State your contribution with a truthful action verb.');
        $add('specificity',$french?'Formulations précises':'Concrete wording',5,$count?1-count($weak)/$count:0,$weak,$weak?($french?'Formulations de responsabilité génériques détectées.':'Generic responsibility phrases detected.'):($french?'Aucune formule générique détectée.':'No generic duty phrases detected.'),$french?'Remplacez les formules générales par votre action réelle.':'Replace generic duties with your actual work.');
        $add('focus',$french?'Phrases lisibles':'Focused sentences',5,$count?count($concise)/$count:0,array_values(array_diff($unique,$concise)),$french?count($concise)." sur {$count} contributions font 45 mots ou moins.":count($concise)." of {$count} contributions are 45 words or fewer.",$french?'Divisez les phrases longues pour faciliter la lecture.':'Split long contribution sentences.');
        $add('repetition',$french?'Sans répétition':'No duplicates',5,$contributions?1-count($dupes)/count($contributions):0,$dupes,$dupes?($french?'Lignes identiques trouvées.':'Repeated contribution lines found.'):($french?'Aucune contribution répétée.':'No repeated contribution lines.'),$french?'Supprimez les doublons, gardez des preuves variées.':'Remove duplicate lines; keep varied evidence.');
        $add('outcomes',$french?'Résultats ou contexte':'Outcomes or context',10,min(count($outcomes)/2,1),$outcomes,$french?count($outcomes).' lignes évoquent un résultat ou une échelle.':count($outcomes).' lines mention an outcome or scale.',$french?'Ajoutez des résultats concrets, même qualitatifs et véridiques.':'Add truthful outcomes, including qualitative ones.');
        foreach($categories as &$category) { $category['score']=array_sum(array_column($category['checks'],'earned'));$category['max']=array_sum(array_column($category['checks'],'max')); }unset($category);
        $checks=array_merge(...array_column($categories,'checks'));
        $raw=array_sum(array_column($checks,'earned'));
        $issues=array_values(array_filter($checks,static fn($c)=>$c['status']==='review'));
        $score=$wordCount<40?null:min(94,$issues?min($raw,89):$raw);
        if ($score!==null && !$email) $score=min($score,69);
        if ($score!==null && !isset($headings['experience'])) $score=min($score,65);
        if ($score!==null && $count<2) $score=min($score,74);
        if ($score!==null && ($missingChars || count($fragmented)>2)) $score=min($score,59);
        $priorities=$issues;
        usort($priorities,static fn($a,$b)=>($b['max']-$b['earned'])<=>($a['max']-$a['earned']));
        $scoreReason=$score===null?($french?'Texte insuffisant : au moins 40 mots lisibles sont nécessaires.':'Insufficient text: at least 40 readable words are needed.'):($issues?($french?'Score plafonné : des problèmes restent à corriger.':'Score capped while document issues remain.'):($french?'Tous les contrôles textuels passent ; le plafond rappelle que le rendu chez un employeur reste inconnu.':'All text checks pass; the ceiling reflects unknown employer parsing.'));
        return ['version'=>self::VERSION,'score'=>$score,'raw_score'=>$raw,'max_score'=>array_sum(array_column($checks,'max')),'words'=>$wordCount,'issues'=>count($issues),'categories'=>$categories,'priorities'=>array_slice($priorities,0,4),'score_reason'=>$scoreReason,'limitations'=>[$french?'Indicateur de qualité du texte, pas un score ATS employeur.':'Text quality indicator, not an employer ATS score.',$french?'La mise en page visuelle, la véracité et le recrutement restent non vérifiés.':'Visual layout, factual claims and hiring outcome remain unverified.']];
    }
}
