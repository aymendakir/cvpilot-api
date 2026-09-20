<?php
namespace App\Http\Middleware;
use App\Models\User;
class Member {function handle($request,\Closure $next){$user=User::find($request->session()->get('user_id'));abort_unless($user&&!$user->suspended&&$user->verified_at&&$request->session()->get('session_version')===$user->session_version,401);if(!$user->last_seen_at||\Illuminate\Support\Carbon::parse($user->last_seen_at)->lt(now()->subMinutes(5))){$user->last_seen_at=now();$user->save();}$request->setUserResolver(fn()=>$user);return $next($request);}}
