<?php
namespace App\Http\Controllers;
use App\Models\Integration;
use App\Services\AiGateway;
use Illuminate\Http\Request;
class IntegrationController{
 function index(AiGateway $ai){return ['items'=>Integration::orderBy('type')->orderBy('provider')->get(['id','provider','type','model','settings','enabled','tested_at','last_error','updated_at']),'catalog'=>$ai->providers(),'job_providers'=>['jsearch','adzuna','jooble','arbeitnow']];}
 function store(Request $r){$d=$r->validate(['provider'=>'required|in:openai,anthropic,gemini,groq,mistral,openrouter,bazaarlink,jsearch,adzuna,jooble,arbeitnow','type'=>'required|in:ai,jobs','secret'=>'nullable|string|max:1000','model'=>'nullable|string|max:120','settings'=>'nullable|array','enabled'=>'required|boolean']);$this->validateType($d);$item=Integration::firstOrNew(['provider'=>$d['provider']]);if(!$item->exists&&empty($d['secret'])&&$d['provider']!=='arbeitnow')abort(422,'API key is required.');$item->fill(['type'=>$d['type'],'model'=>$d['model']??null,'settings'=>$d['settings']??null,'enabled'=>$d['enabled']]);if(!empty($d['secret']))$item->secret=$d['secret'];elseif($d['provider']==='arbeitnow'&&!$item->secret)$item->secret='public';$item->save();AuthController::audit($r,'integration_saved:'.$item->provider,$r->user()->id);return ['message'=>'Integration saved with encrypted credentials.','item'=>$item->only(['id','provider','type','model','enabled','updated_at'])];}

 function update(Request $r,Integration $integration){
  $d=$r->validate(['secret'=>'nullable|string|max:1000','model'=>'nullable|string|max:120','settings'=>'nullable|array','enabled'=>'required|boolean']);
  if(empty($d['secret']))unset($d['secret']);$integration->fill($d);$integration->tested_at=null;$integration->last_error=null;$integration->save();
  AuthController::audit($r,'integration_updated:'.$integration->provider,$r->user()->id);return ['item'=>$integration];
 }
 private function validateType(array $d):void{
  $jobs=['jsearch','adzuna','jooble','arbeitnow'];abort_unless(($d['type']==='jobs')===in_array($d['provider'],$jobs,true),422,'Provider does not match integration type.');
 }
 function test(Request $r,Integration $integration,AiGateway $ai){try{if($integration->type==='ai')$result=$ai->test($integration);else{$result=app(\App\Services\JobSearchService::class)->search(['provider'=>$integration->provider,'q'=>'customer service','country'=>'DE','country_name'=>'Germany','page'=>1]);$result=['ok'=>true,'message'=>'Connected. '.count($result['data']).' sample jobs returned.'];}$integration->update(['tested_at'=>now(),'last_error'=>null]);return $result;}catch(\Throwable $e){$integration->update(['last_error'=>mb_substr($e->getMessage(),0,500)]);return response()->json(['ok'=>false,'message'=>'Connection failed. Check the key, model and provider settings.'],422);}}
 function destroy(Request $r,Integration $integration){$provider=$integration->provider;$integration->delete();AuthController::audit($r,'integration_deleted:'.$provider,$r->user()->id);return response()->noContent();}
}
