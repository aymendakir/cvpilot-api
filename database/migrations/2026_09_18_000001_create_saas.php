<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 function up():void {
 Schema::create('users',function(Blueprint $t){$t->id();$t->string('name');$t->string('email')->unique();$t->string('password');$t->unsignedInteger('session_version')->default(1);$t->string('role')->default('user');$t->boolean('suspended')->default(false);$t->timestamp('verified_at')->nullable();$t->string('country',2)->nullable();$t->string('city')->nullable();$t->string('phone')->nullable();$t->string('language',8)->default('en');$t->timestamps();});
 Schema::create('audit_events',function(Blueprint $t){$t->id();$t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();$t->string('event')->index();$t->string('ip',45)->nullable();$t->string('user_agent',512)->nullable();$t->timestamp('created_at')->useCurrent()->index();});
 Schema::create('jobs',function(Blueprint $t){$t->id();$t->string('title');$t->string('company');$t->string('country',2)->index();$t->string('city')->nullable();$t->text('description');$t->text('url');$t->string('source');$t->timestamp('published_at')->index();$t->timestamps();});
 Schema::create('job_matches',function(Blueprint $t){$t->id();$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->foreignId('job_id')->constrained()->cascadeOnDelete();$t->unsignedTinyInteger('score');$t->text('explanation');$t->timestamps();$t->unique(['user_id','job_id']);});
 Schema::create('integrations',function(Blueprint $t){$t->id();$t->string('provider')->unique();$t->text('secret');$t->string('model')->nullable();$t->boolean('enabled')->default(false);$t->timestamps();});
 }
 function down():void{foreach(['integrations','job_matches','jobs','audit_events','users'] as $table)Schema::dropIfExists($table);}
};
