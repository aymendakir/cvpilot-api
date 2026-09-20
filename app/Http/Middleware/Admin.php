<?php
namespace App\Http\Middleware;
class Admin {function handle($request,\Closure $next){abort_unless($request->user()?->role==='admin',403);return $next($request);}}
