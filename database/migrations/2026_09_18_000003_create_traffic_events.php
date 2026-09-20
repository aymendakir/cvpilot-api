<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
 function up():void {
  Schema::create('traffic_events',function(Blueprint $t){
   $t->id();
   $t->string('visitor_id',64)->index();
   $t->string('session_id',64)->index();
   $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
   $t->string('event_type',32)->default('page_view')->index();
   $t->string('path',500)->index();
   $t->string('referrer_host')->nullable()->index();
   $t->string('utm_source')->nullable()->index();
   $t->string('utm_medium')->nullable();
   $t->string('utm_campaign')->nullable();
   $t->string('country_code',2)->nullable()->index();
   $t->string('city')->nullable();
   $t->string('device_type',32)->nullable()->index();
   $t->string('browser',64)->nullable();
   $t->string('os',64)->nullable();
   $t->timestamp('occurred_at')->useCurrent()->index();
  });
 }
 function down():void {Schema::dropIfExists('traffic_events');}
};
