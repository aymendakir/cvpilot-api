<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
 function up():void{
  Schema::create('job_searches',function(Blueprint $t){
   $t->id();$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->string('name');$t->string('query');$t->string('country',2);$t->string('country_name')->nullable();$t->string('city')->nullable();$t->string('experience',30)->nullable();$t->string('work_mode',20)->default('any');$t->json('filters')->nullable();$t->boolean('alerts_enabled')->default(false);$t->string('alert_frequency',20)->default('weekly');$t->timestamp('last_run_at')->nullable();$t->timestamps();
  });
  Schema::create('job_search_events',function(Blueprint $t){
   $t->id();$t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();$t->string('provider',30)->nullable()->index();$t->string('query');$t->string('country',2)->nullable()->index();$t->string('city')->nullable();$t->unsignedSmallInteger('results_count')->default(0);$t->unsignedInteger('latency_ms')->default(0);$t->boolean('success')->default(true)->index();$t->text('error')->nullable();$t->timestamp('created_at')->useCurrent()->index();
  });
 }
 function down():void{Schema::dropIfExists('job_search_events');Schema::dropIfExists('job_searches');}
};
