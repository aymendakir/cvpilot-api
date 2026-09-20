<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Crypt};
use App\Models\User;
class AdminController {
 function summary(Request $r){AuthController::audit($r,'dashboard_view',$r->user()->id);return ['users'=>User::count(),'verified_users'=>User::whereNotNull('verified_at')->count(),'new_users_7d'=>User::where('created_at','>=',now()->subDays(7))->count(),'active_users_24h'=>User::where('last_seen_at','>=',now()->subDay())->count(),'failed_logins_24h'=>DB::table('audit_events')->where('event','login_failed')->where('created_at','>=',now()->subDay())->count(),'cv_documents'=>DB::table('cv_documents')->count(),'ats_reports'=>DB::table('ats_reports')->count(),'applications'=>DB::table('applications')->count(),'ai_requests_7d'=>DB::table('ai_usage')->where('created_at','>=',now()->subDays(7))->count(),'ai_failures_24h'=>DB::table('ai_usage')->where('success',false)->where('created_at','>=',now()->subDay())->count(),'integrations_enabled'=>DB::table('integrations')->where('enabled',true)->count(),'note'=>'Counts come from the application database. IP metadata is recorded only for security auditing.'];}
 function users(Request $r){$r->validate(['search'=>'nullable|string|max:120','page'=>'nullable|integer|min:1']);return User::when($r->search,fn($q)=>$q->where(fn($s)=>$s->where('name','like','%'.$r->search.'%')->orWhere('email','like','%'.$r->search.'%')))->orderByDesc('id')->paginate(20);}
 function suspend(Request $r,User $user){abort_if($user->role==='admin',422,'Admin accounts cannot be suspended here.');$d=$r->validate(['suspended'=>'required|boolean']);$user->suspended=$d['suspended'];$user->session_version++;$user->save();AuthController::audit($r,'user_'.$user->id.($user->suspended?'_suspended':'_enabled'),$r->user()->id);return $user;}
 function logs(Request $r){$r->validate(['event'=>'nullable|string|max:100','page'=>'nullable|integer|min:1']);return DB::table('audit_events')->leftJoin('users','users.id','=','audit_events.user_id')->select('audit_events.*','users.name as user_name','users.email as user_email')->when($r->event,fn($q)=>$q->where('event',$r->event))->orderByDesc('audit_events.id')->paginate(30);}

 function detail(Request $r,User $user){
  $counts=[];foreach(['cv_versions','interview_sessions','job_workspaces','applications','career_reports'] as $table)$counts[$table]=DB::table($table)->where('user_id',$user->id)->count();
  AuthController::audit($r,'user_reviewed:'.$user->id,$r->user()->id);
  return ['user'=>$user,'counts'=>$counts,'activity'=>DB::table('audit_events')->where('user_id',$user->id)->latest('id')->limit(40)->get(['event','created_at']),
   'reviews'=>\App\Models\AdminReviewItem::where('user_id',$user->id)->where('expires_at','>',now())->latest('updated_at')->limit(100)->get(),
   'uploads'=>\App\Models\CvDocument::where('user_id',$user->id)->where('expires_at','>',now())->latest()->get()];
 }
 function applications(Request $r){
  $r->validate(['page'=>'nullable|integer|min:1','search'=>'nullable|string|max:120']);
  return \App\Models\AdminReviewItem::with('user:id,name,email')->where('kind','application')->where('expires_at','>',now())
   ->when($r->search,fn($q)=>$q->whereHas('user',fn($u)=>$u->where('name','like','%'.$r->search.'%')->orWhere('email','like','%'.$r->search.'%')))->latest('updated_at')->paginate(20);
 }
 function warning(Request $r,User $user,\App\Services\PlatformMail $mail){
  $d=$r->validate(['subject'=>'required|string|max:180','message'=>'required|string|min:10|max:5000']);
  try{$mail->send($user->email,$d['subject'],'emails.notice',['name'=>$user->name,'heading'=>$d['subject'],'message'=>$d['message']]);}
  catch(\Throwable){return response()->json(['message'=>'Warning was not sent. Check SMTP settings and try again.'],422);}
  AuthController::audit($r,'warning_sent:'.$user->id,$r->user()->id);return ['message'=>'Warning email sent.'];
 }
 function download(Request $r,\App\Models\CvDocument $cv){
  abort_unless($cv->expires_at?->isFuture(),404);AuthController::audit($r,'upload_reviewed:'.$cv->id,$r->user()->id);
  return \Illuminate\Support\Facades\Storage::disk('local')->download($cv->disk_path,$cv->name,['Cache-Control'=>'private, no-store']);
 }
 function integrations(){return DB::table('integrations')->select('provider','model','enabled','updated_at')->get();}
 function integration(Request $r){$d=$r->validate(['provider'=>'required|in:openai','secret'=>'required|string|max:500','model'=>'required|string|max:100','enabled'=>'required|boolean']);DB::table('integrations')->updateOrInsert(['provider'=>$d['provider']],['secret'=>Crypt::encryptString($d['secret']),'model'=>$d['model'],'enabled'=>$d['enabled'],'updated_at'=>now(),'created_at'=>now()]);AuthController::audit($r,'integration_updated',$r->user()->id);return ['message'=>'Encrypted credentials saved. Connection has not been tested.'];}
}
