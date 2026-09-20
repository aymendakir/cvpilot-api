<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController {
 function store(Request $r){
  $d=$r->validate([
   'consent'=>'required|accepted','visitor_id'=>'required|string|min:16|max:100','session_id'=>'required|string|min:16|max:100',
   'path'=>'required|string|max:500','referrer'=>'nullable|url|max:1000','utm_source'=>'nullable|string|max:255',
   'utm_medium'=>'nullable|string|max:255','utm_campaign'=>'nullable|string|max:255'
  ]);
  $key=(string)config('app.key');$agent=substr($r->userAgent()??'',0,512);[$device,$browser,$os]=$this->parseAgent($agent);
  $country=strtoupper(substr((string)$r->header('CF-IPCountry',''),0,2));if(!preg_match('/^[A-Z]{2}$/',$country))$country=null;$city=trim(substr(urldecode((string)$r->header('CF-IPCity','')),0,120))?:null;
  $referrer=null;if(!empty($d['referrer']))$referrer=parse_url($d['referrer'],PHP_URL_HOST)?:null;
  DB::table('traffic_events')->insert([
   'visitor_id'=>hash_hmac('sha256',$d['visitor_id'],$key),'session_id'=>hash_hmac('sha256',$d['session_id'],$key),
   'user_id'=>$r->session()->get('user_id'),'event_type'=>'page_view','path'=>'/'.ltrim(parse_url($d['path'],PHP_URL_PATH)?:'','/'),
   'referrer_host'=>$referrer,'utm_source'=>$d['utm_source']??null,'utm_medium'=>$d['utm_medium']??null,'utm_campaign'=>$d['utm_campaign']??null,
   'country_code'=>$country,'city'=>$city,'device_type'=>$device,'browser'=>$browser,'os'=>$os,'occurred_at'=>now()
  ]);
  return response()->json(['recorded'=>true],201);
 }

 function report(Request $r){
  $days=(int)$r->input('days',30);if(!in_array($days,[7,30,90],true))$days=30;$since=now()->subDays($days-1)->startOfDay();
  $base=DB::table('traffic_events')->where('occurred_at','>=',$since);
  $sessions=(clone $base)->distinct()->count('session_id');
  $singlePage=DB::query()->fromSub((clone $base)->select('session_id')->groupBy('session_id')->havingRaw('COUNT(*) = 1'),'single_sessions')->count();
  $series=(clone $base)->selectRaw('DATE(occurred_at) as day, COUNT(*) as pageviews, COUNT(DISTINCT visitor_id) as visitors')->groupByRaw('DATE(occurred_at)')->orderBy('day')->get();
  return [
   'days'=>$days,
   'summary'=>['visitors'=>(clone $base)->distinct()->count('visitor_id'),'sessions'=>$sessions,'pageviews'=>(clone $base)->count(),'bounce_rate'=>$sessions?round(($singlePage/$sessions)*100,1):0],
   'timeseries'=>$series,
   'pages'=>(clone $base)->select('path')->selectRaw('COUNT(*) as views, COUNT(DISTINCT visitor_id) as visitors')->groupBy('path')->orderByDesc('views')->limit(8)->get(),
   'referrers'=>(clone $base)->selectRaw("COALESCE(referrer_host, 'Direct') as label, COUNT(*) as visits")->groupBy('referrer_host')->orderByDesc('visits')->limit(8)->get(),
   'countries'=>(clone $base)->selectRaw("COALESCE(NULLIF(CONCAT_WS(' · ', country_code, city), ''), 'Unknown') as label, COUNT(DISTINCT visitor_id) as visitors")->groupBy('country_code','city')->orderByDesc('visitors')->limit(8)->get(),
   'devices'=>(clone $base)->selectRaw("COALESCE(device_type, 'Unknown') as label, COUNT(*) as visits")->groupBy('device_type')->orderByDesc('visits')->get(),
   'recent'=>(clone $base)->select('path','referrer_host','country_code','city','device_type','browser','os','occurred_at')->orderByDesc('occurred_at')->limit(20)->get(),
  ];
 }

 private function parseAgent(string $ua):array{
  $device=preg_match('/bot|crawler|spider/i',$ua)?'Bot':(preg_match('/ipad|tablet/i',$ua)?'Tablet':(preg_match('/mobile|iphone|android/i',$ua)?'Mobile':'Desktop'));
  $browser=preg_match('/Edg\//',$ua)?'Edge':(preg_match('/OPR\//',$ua)?'Opera':(preg_match('/Chrome\//',$ua)?'Chrome':(preg_match('/Firefox\//',$ua)?'Firefox':(preg_match('/Safari\//',$ua)?'Safari':'Other'))));
  $os=preg_match('/Windows/i',$ua)?'Windows':(preg_match('/Android/i',$ua)?'Android':(preg_match('/iPhone|iPad/i',$ua)?'iOS':(preg_match('/Mac OS/i',$ua)?'macOS':(preg_match('/Linux/i',$ua)?'Linux':'Other'))));
  return [$device,$browser,$os];
 }
}
