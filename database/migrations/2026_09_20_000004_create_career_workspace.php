<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 function up():void{
  Schema::create('job_workspaces',function(Blueprint $t){$t->id();$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->string('title');$t->string('company')->nullable();$t->text('job_url')->nullable();$t->longText('job_description');$t->longText('cv_text');$t->string('status',30)->default('active');$t->timestamps();});
  Schema::create('cv_versions',function(Blueprint $t){$t->id();$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->foreignId('job_workspace_id')->nullable()->constrained()->nullOnDelete();$t->string('name');$t->longText('content');$t->string('source',30)->default('manual');$t->timestamps();});
  Schema::create('interview_sessions',function(Blueprint $t){$t->id();$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->foreignId('job_workspace_id')->nullable()->constrained()->nullOnDelete();$t->string('title');$t->string('company')->nullable();$t->longText('cv_text');$t->longText('job_description');$t->json('transcript');$t->longText('feedback')->nullable();$t->string('status',20)->default('active');$t->timestamps();});
  Schema::create('career_reports',function(Blueprint $t){$t->id();$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->string('type',40)->index();$t->json('input')->nullable();$t->longText('output');$t->timestamps();});
  Schema::table('applications',function(Blueprint $t){$t->foreignId('cv_version_id')->nullable()->after('user_id')->constrained('cv_versions')->nullOnDelete();$t->string('salary')->nullable()->after('match_score');$t->date('application_date')->nullable()->after('salary');$t->timestamp('reminder_at')->nullable()->after('application_date');$t->timestamp('follow_up_at')->nullable()->after('reminder_at');$t->longText('job_description')->nullable()->after('url');});
 }
 function down():void{Schema::table('applications',function(Blueprint $t){$t->dropConstrainedForeignId('cv_version_id');$t->dropColumn(['salary','application_date','reminder_at','follow_up_at','job_description']);});foreach(['career_reports','interview_sessions','cv_versions','job_workspaces'] as $table)Schema::dropIfExists($table);}
};
