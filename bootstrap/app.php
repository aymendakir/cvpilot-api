<?php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
return Application::configure(basePath:dirname(__DIR__))
 ->withRouting(web:__DIR__.'/../routes/web.php',health:'/up',commands:__DIR__.'/../routes/console.php')
 ->withSchedule(function(\Illuminate\Console\Scheduling\Schedule $schedule){$schedule->call(new App\Services\TemporaryDataCleanup)->name('cvpilot-prune-temporary')->everyMinute()->withoutOverlapping();})
 ->withMiddleware(function(Middleware $middleware){
  $middleware->append(App\Http\Middleware\SecurityHeaders::class);
  $middleware->alias(['member'=>App\Http\Middleware\Member::class,'admin'=>App\Http\Middleware\Admin::class]);
 })
 ->withExceptions(function(Exceptions $exceptions):void{
  $exceptions->render(function(\Throwable $e,\Illuminate\Http\Request $request){
   if($request->is('api/*')&&!$e instanceof \Illuminate\Validation\ValidationException){
    $status=$e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface?$e->getStatusCode():500;
    if($status>=500)return response()->json(['message'=>'Service temporarily unavailable.'],500);
   }
  });
 })
 ->create();
