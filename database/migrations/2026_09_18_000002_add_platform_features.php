<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 function up():void{
  Schema::table('users',function(Blueprint $t){$t->string('target_role')->nullable();$t->string('experience_level')->nullable();$t->json('preferred_countries')->nullable();$t->json('work_modes')->nullable();$t->json('preferences')->nullable();});
  Schema::table('integrations',function(Blueprint $t){$t->string('type',20)->default('ai')->after('provider');$t->json('settings')->nullable()->after('model');$t->timestamp('tested_at')->nullable();$t->text('last_error')->nullable();});
  Schema::create('cv_documents',function(Blueprint $t){$t->id();$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->string('name');$t->string('disk_path');$t->string('mime',100);$t->unsignedBigInteger('size');$t->longText('extracted_text');$t->boolean('is_primary')->default(false);$t->timestamps();});
  Schema::create('ats_reports',function(Blueprint $t){$t->id();$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->foreignId('cv_document_id')->nullable()->constrained()->nullOnDelete();$t->unsignedTinyInteger('score');$t->json('breakdown');$t->json('matched_keywords');$t->json('missing_keywords');$t->json('suggestions');$t->string('provider')->default('local');$t->timestamps();});
  Schema::create('applications',function(Blueprint $t){$t->id();$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->string('external_job_id')->nullable();$t->string('title');$t->string('company');$t->text('url');$t->string('status',30)->default('saved');$t->unsignedTinyInteger('match_score')->nullable();$t->text('notes')->nullable();$t->timestamp('applied_at')->nullable();$t->timestamps();});
  Schema::create('ai_usage',function(Blueprint $t){$t->id();$t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();$t->string('provider');$t->string('model');$t->string('feature');$t->unsignedInteger('input_tokens')->default(0);$t->unsignedInteger('output_tokens')->default(0);$t->unsignedInteger('latency_ms')->default(0);$t->boolean('success')->default(true);$t->text('error')->nullable();$t->timestamp('created_at')->useCurrent()->index();});
 }
 function down():void{foreach(['ai_usage','applications','ats_reports','cv_documents'] as $table)Schema::dropIfExists($table);Schema::table('integrations',function(Blueprint $t){$t->dropColumn(['type','settings','tested_at','last_error']);});Schema::table('users',function(Blueprint $t){$t->dropColumn(['target_role','experience_level','preferred_countries','work_modes','preferences']);});}
};
