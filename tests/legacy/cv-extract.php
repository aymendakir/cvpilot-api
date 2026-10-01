<?php
// Run: php tests/cv-extract.php  (uses an in-memory SQLite database; never touches MySQL)
require __DIR__.'/../../vendor/autoload.php';
putenv('APP_KEY=base64:'.base64_encode(str_repeat('k',32)));$_ENV['APP_KEY']=$_SERVER['APP_KEY']=getenv('APP_KEY');
$app=require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.sqlite'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true],'database.default'=>'sqlite','session.driver'=>'array','session.secure'=>false,'cache.default'=>'array','app.debug'=>false]);
Illuminate\Support\Facades\DB::purge();
Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
use Illuminate\Http\Request;use Illuminate\Http\UploadedFile;use App\Models\{User,CvDocument};
function check($ok,$m){if(!$ok)throw new RuntimeException($m);echo "ok - $m\n";}
$user=User::forceCreate(['name'=>'T','email'=>'t@example.com','password'=>'x','verified_at'=>now(),'session_version'=>1]);
$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
function call($kernel,$files,$login=true,$user=null){
 $session=app('session')->driver();$session->start();$session->flush();$session->put('_token','tok');
 if($login){$session->put('user_id',$user->id);$session->put('session_version',1);}
 $req=Request::create('/api/v1/cv-documents/extract','POST',[],[],$files,['HTTP_ACCEPT'=>'application/json','HTTP_X_CSRF_TOKEN'=>'tok']);
 $req->setLaravelSession($session);
 // array driver: make StartSession reuse this store
 app()->instance('session.store',$session);
 $res=$kernel->handle($req);return [$res->getStatusCode(),json_decode($res->getContent(),true)];
}
function tmp($name,$bytes,$mime='application/octet-stream'){$p=tempnam(sys_get_temp_dir(),'cvx');file_put_contents($p,$bytes);return new UploadedFile($p,$name,$mime,null,true);}
$cv="Jane Doe\njane@example.com\nExperience\nBuilt a client dashboard for 50 customers.\nEducation\nComputer science degree 2023.\n";
function minimalPdf($text){$s="BT /F1 12 Tf 50 700 Td ($text) Tj ET";$o=["<</Type/Catalog/Pages 2 0 R>>","<</Type/Pages/Kids[3 0 R]/Count 1>>","<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>","<</Length ".strlen($s).">>\nstream\n$s\nendstream","<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>"];$pdf="%PDF-1.4\n";$off=[];foreach($o as $i=>$b){$off[]=strlen($pdf);$pdf.=($i+1)." 0 obj\n$b\nendobj\n";}$x=strlen($pdf);$pdf.="xref\n0 ".(count($o)+1)."\n0000000000 65535 f \n";foreach($off as $f)$pdf.=sprintf("%010d 00000 n \n",$f);return $pdf."trailer<</Size ".(count($o)+1)."/Root 1 0 R>>\nstartxref\n$x\n%%EOF";}
[$s,$b]=call($kernel,['file'=>tmp('cv.txt',$cv,'text/plain')],true,$user);
check($s===200&&str_contains($b['text'],'jane@example.com'),'authenticated TXT returns {text}');
[$s,$b]=call($kernel,['file'=>tmp('cv.pdf',minimalPdf('Jane Doe developer with React Laravel and MySQL experience building dashboards'),'application/pdf')],true,$user);
check($s===200&&str_contains($b['text'],'Laravel'),'text PDF is extracted');
[$s,$b]=call($kernel,['file'=>tmp('photo.pdf',minimalPdf('x'),'application/pdf')],true,$user);
check($s===422&&str_contains($b['message'],'No readable'),'near-empty/scanned PDF gives explicit 422');
[$s,$b]=call($kernel,['file'=>tmp('cv.txt',$cv)],false);
check($s===401,'unauthenticated request is rejected (401)');
[$s]=call($kernel,[],true,$user);
check($s===422,'missing file fails validation');
[$s,$b]=call($kernel,['file'=>tmp('evil.pdf',"MZ\x90\x00\x03\x00\x00\x00binary")],true,$user);
check($s===422,'binary renamed to .pdf is rejected by content');
[$s,$b]=call($kernel,['file'=>tmp('fake.docx',"PK\x03\x04garbage")],true,$user);
check($s===422,'invalid zip renamed to .docx is rejected');
[$s]=call($kernel,['file'=>tmp('big.txt',str_repeat('a',15361*1024))],true,$user);
check($s===422,'file above 15 MB is rejected');
[$s,$b]=call($kernel,['file'=>tmp('broken.pdf',"%PDF-1.4\nnot really a pdf")],true,$user);
check($s===422,'corrupt PDF is a 422, not a 500');
check(CvDocument::count()===0,'extract never creates a CvDocument');
echo "ALL PASSED\n";
