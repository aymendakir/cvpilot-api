<?php
// Standalone integration regression: php tests/workspace.php. Uses only an in-memory SQLite DB.
require __DIR__.'/../vendor/autoload.php';
putenv('APP_ENV=testing'); putenv('APP_KEY=base64:'.base64_encode(random_bytes(32)));
putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:'); putenv('SESSION_SECURE_COOKIE=false');
$app=require __DIR__.'/../bootstrap/app.php';
$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);$kernel->bootstrap();
$root=sys_get_temp_dir().'/cvpilot-test-'.bin2hex(random_bytes(6));mkdir($root,0700,true);
config(['database.default'=>'sqlite','database.connections.sqlite'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true],'cache.default'=>'array','cache.stores.array'=>['driver'=>'array'],'session.driver'=>'array','session.secure'=>false,'filesystems.disks.local.root'=>$root,'hashing.bcrypt.rounds'=>4]);
Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
class FakeMail extends App\Services\PlatformMail {public array $messages=[];public function send(string $to,string $subject,string $view,array $data):void{$this->messages[]=compact('to','subject','view','data');}}
$mail=new FakeMail;$app->instance(App\Services\PlatformMail::class,$mail);
$app->instance(App\Services\AiGateway::class,new class extends App\Services\AiGateway {public function chat(string $prompt,string $feature='assistant',?int $userId=null,?string $provider=null):array{return ['answer'=>'Test response with evidence and a relevant question.','provider'=>'test','model'=>'test'];}});
function check(bool $condition,string $label):void {if(!$condition)throw new RuntimeException($label);echo "PASS $label\n";}
function api(string $method,string $path,array $body=[],array &$cookies=[],array $files=[]):array {
 global $kernel;
 $request=Illuminate\Http\Request::create('http://localhost/api/'.$path,$method,[],$cookies,$files,['HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json','REMOTE_ADDR'=>'127.0.0.1'],json_encode($body));
 $response=$kernel->handle($request);foreach($response->headers->getCookies() as $cookie)$cookies[$cookie->getName()]=$cookie->getValue();
 $result=[$response->getStatusCode(),json_decode($response->getContent(),true),$response];$kernel->terminate($request,$response);return $result;
}
function member(string $email,string $role='user'):App\Models\User{$u=new App\Models\User;$u->name='Test Person';$u->email=$email;$u->password=Illuminate\Support\Facades\Hash::make('secure-test-password');$u->role=$role;$u->verified_at=now();$u->save();return $u;}
try {
 $user=member('user@example.test');$other=member('other@example.test');$admin=member('admin@example.test','admin');$guest=[];$client=[];$owner=[];$stranger=[];
 check(api('GET','me',[],$guest)[0]===401,'guest cannot access saved work');
 for($i=0;$i<3;$i++)check(api('POST','otp/request',['email'=>$user->email,'purpose'=>'verify'],$guest)[0]===200,'OTP request accepted');
 $limited=api('POST','otp/request',['email'=>$user->email,'purpose'=>'verify'],$guest);check($limited[0]===429&&$limited[2]->headers->has('Retry-After'),'OTP limit has retry duration');
 check(api('POST','login',['email'=>$user->email,'password'=>'secure-test-password'],$client)[0]===200,'OTP limit does not block login');
 check(api('POST','login',['email'=>$other->email,'password'=>'secure-test-password'],$stranger)[0]===200,'second account can sign in');
 check(api('POST','login',['email'=>$admin->email,'password'=>'secure-test-password'],$owner)[0]===200,'administrator signs in');
 check(api('GET','admin/users',[],$client)[0]===403,'member cannot list platform users');
 $content='Alex Morgan\nCustomer service professional with experience supporting guests and coordinating reservations.';
 $saved=api('POST','career/cv-versions',['name'=>'My CV','content'=>$content,'source'=>'manual','builder_data'=>['cv'=>['name'=>'Alex'],'design'=>['layout'=>'modern']]],$client);check($saved[0]===201,'CV version is persisted');$id=$saved[1]['id'];
 check(api('GET','career/cv-versions/'.$id,[],$stranger)[0]===404,'other account cannot read CV');
 check(api('PATCH','career/cv-versions/'.$id,['name'=>'Updated CV','content'=>$content.' Updated.'],$client)[0]===200,'owner can update CV');
 check(api('GET','career/library?kind=cv',[],$client)[1]['counts']['cv']===1,'profile library returns saved CV');
 $job=['title'=>'Guest Services','company'=>'Example Hotel','cv_text'=>$content,'job_description'=>'Welcome guests, coordinate reservations, respond to customer enquiries and collaborate with the hotel team.'];
 $workspace=api('POST','career/workspaces',$job,$client);check($workspace[0]===201,'job workspace persists');
 check(api('GET','career/workspaces/'.$workspace[1]['id'],[],$stranger)[0]===404,'job workspace enforces ownership');
 $interview=api('POST','career/interviews',$job,$client);check($interview[0]===200,'interview starts');$sid=$interview[1]['session_id'];
 check(api('POST',"career/interviews/$sid/reply",['answer'=>'I clarify the guest request and coordinate with my team.'],$client)[0]===200,'interview answer persists');
 $restored=api('GET',"career/interviews/$sid",[],$client);check(count($restored[1]['transcript'])===3,'interview transcript restores');
 check(api('GET',"career/interviews/$sid",[],$stranger)[0]===404,'other account cannot read interview');
 check(api('POST',"career/interviews/$sid/finish",[],$client)[0]===200,'interview completes');
 check(api('POST',"career/interviews/$sid/reply",['answer'=>'Another answer'],$client)[0]===422,'completed interview rejects new replies');
 $application=api('POST','applications',['title'=>'Guest Services','company'=>'Example Hotel','url'=>'https://example.test/job','notes'=>'Follow up next week.'],$client);check($application[0]===201,'application persists');
 check(api('GET','admin/applications',[],$owner)[1]['total']===1,'admin sees fresh application copy');
 check(api('POST','ai/cover-letter',$job+['position'=>'Guest Services'],$client)[0]===200,'cover letter generated');
 check(api('GET','career/library?kind=report',[],$client)[1]['counts']['report']===1,'generated letter is in profile history');
 $key=api('POST','admin/integrations',['provider'=>'openai','type'=>'ai','secret'=>'test-secret','model'=>'test-model','enabled'=>true],$owner);check($key[0]===200,'admin creates API integration');$kid=$key[1]['item']['id'];
 check(!str_contains(Illuminate\Support\Facades\DB::table('integrations')->value('secret'),'test-secret'),'API key is encrypted in storage');
 check(api('PATCH','admin/integrations/'.$kid,['model'=>'updated-model','secret'=>'','enabled'=>false],$owner)[0]===200,'API integration can be updated');
 check(App\Models\Integration::find($kid)->secret==='test-secret','blank key update preserves existing secret');
 check(!str_contains(json_encode(api('GET','admin/integrations',[],$owner)[1]),'test-secret'),'API responses omit credentials');
 check(api('DELETE','admin/integrations/'.$kid,[],$owner)[0]===204,'API integration can be deleted');
 $smtp=['host'=>'smtp.example.test','port'=>587,'encryption'=>'tls','username'=>'test','password'=>'smtp-secret','from_address'=>'sender@example.test','from_name'=>'CVPilot'];
 check(api('PUT','admin/smtp',$smtp,$owner)[0]===200,'SMTP settings save');
 check(!str_contains(json_encode(api('GET','admin/smtp',[],$owner)[1]),'smtp-secret'),'SMTP read omits password');
 $smtp['password']='';check(api('PUT','admin/smtp',$smtp,$owner)[0]===200&&App\Models\MailSetting::first()->password==='smtp-secret','SMTP blank password preserves secret');
 check(api('GET','admin/smtp',[],$client)[0]===403,'SMTP is admin-only');
 check(api('POST','admin/users/'.$user->id.'/warning',['subject'=>'Account notice','message'=>'Please review your account activity.'],$owner)[0]===200,'warning uses configured mail service');
 check(end($mail->messages)['to']===$user->email,'warning addressed only to selected user');
 $template=['name'=>'Example','description'=>'A template','design'=>['layout'=>'modern','accent'=>'#245c73','font'=>'sans','spacing'=>'comfortable'],'sample'=>['name'=>'Alex','experience'=>[],'education'=>[]],'published'=>false];
 $t=api('POST','admin/cv-templates',$template,$owner);check($t[0]===201,'template draft created');$tid=$t[1]['id'];
 check(count(api('GET','cv-templates',[],$guest)[1])===0,'draft template is not public');$template['published']=true;
 check(api('PATCH','admin/cv-templates/'.$tid,$template,$owner)[0]===200,'template can be published');
 check(count(api('GET','cv-templates',[],$guest)[1])===1,'published template available to builder');
 $path='cv/'.$user->id.'/test.txt';Illuminate\Support\Facades\Storage::disk('local')->put($path,$content);
 $doc=App\Models\CvDocument::create(['user_id'=>$user->id,'name'=>'test.txt','disk_path'=>$path,'mime'=>'text/plain','size'=>strlen($content),'extracted_text'=>$content,'expires_at'=>now()->addHours(48)]);
 check(api('GET','admin/users/'.$user->id,[],$owner)[1]['uploads'][0]['id']===$doc->id,'admin sees unexpired uploaded CV');
 Illuminate\Support\Carbon::setTestNow(now()->addHours(49));
 check(api('GET','admin/applications',[],$owner)[1]['total']===0,'expired admin copies are inaccessible before cleanup');
 check(api('GET','admin/uploads/'.$doc->id,[],$owner)[0]===404,'expired original file cannot be downloaded');
 app(App\Services\TemporaryDataCleanup::class)();
 check(!Illuminate\Support\Facades\Storage::disk('local')->exists($path)&&!App\Models\CvDocument::find($doc->id),'cleanup deletes expired file and extracted text');
 check(App\Models\AdminReviewItem::count()===0,'cleanup removes temporary admin copies');
 check(App\Models\CvVersion::count()===1&&App\Models\InterviewSession::count()===1&&App\Models\JobWorkspace::count()===1&&App\Models\Application::count()===1&&App\Models\CareerReport::count()===1,'cleanup preserves all user-saved work');
 Illuminate\Support\Carbon::setTestNow();
 check(api('PATCH','admin/users/'.$user->id,['suspended'=>true],$owner)[0]===200,'admin can block user');
 check(api('GET','me',[],$client)[0]===401,'blocked user session is revoked');
 check(api('PATCH','admin/users/'.$user->id,['suspended'=>false],$owner)[0]===200,'admin can unblock user');
 check(api('GET','me',[],$client)[0]===401,'unblocking does not restore revoked sessions');
 $html=view('emails.verification',['name'=>'Alex <script>','code'=>'123456','purpose'=>'verify'])->render();check(str_contains($html,'123456')&&!str_contains($html,'Alex <script>'),'verification email renders and escapes personal data');
 echo "All workspace integration checks passed.\n";
}finally{Illuminate\Support\Carbon::setTestNow();(new Illuminate\Filesystem\Filesystem)->deleteDirectory($root);}
