<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('users', fn (Blueprint $t) => $t->timestamp('last_seen_at')->nullable()->index());
        Schema::table('cv_versions', fn (Blueprint $t) => $t->json('builder_data')->nullable());
        Schema::table('cv_documents', fn (Blueprint $t) => $t->timestamp('expires_at')->nullable()->index());
        \Illuminate\Support\Facades\DB::table('cv_documents')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) \Illuminate\Support\Facades\DB::table('cv_documents')->where('id', $row->id)->update(['expires_at' => \Illuminate\Support\Carbon::parse($row->created_at)->addHours(48)]);
        });
        Schema::create('mail_settings', function (Blueprint $t) {
            $t->id(); $t->string('host'); $t->unsignedSmallInteger('port')->default(587);
            $t->string('encryption', 10)->default('tls'); $t->string('username')->nullable();
            $t->text('password')->nullable(); $t->string('from_address'); $t->string('from_name')->default('CVPilot AI'); $t->timestamps();
        });
        Schema::create('cv_templates', function (Blueprint $t) {
            $t->id(); $t->string('name', 120); $t->string('description')->nullable();
            $t->json('design'); $t->json('sample'); $t->boolean('published')->default(false); $t->timestamps();
        });
        Schema::create('admin_review_items', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 30); $t->unsignedBigInteger('record_id'); $t->longText('payload');
            $t->timestamp('expires_at')->index(); $t->timestamps(); $t->unique(['kind', 'record_id']);
        });
        // Import only the still-valid review window for existing accounts.
        foreach (['cv'=>\App\Models\CvVersion::class,'workspace'=>\App\Models\JobWorkspace::class,'interview'=>\App\Models\InterviewSession::class,'application'=>\App\Models\Application::class,'report'=>\App\Models\CareerReport::class] as $kind=>$model) {
            $model::where('updated_at','>',now()->subHours(48))->chunkById(100,function($items)use($kind){
                foreach($items as $item)\App\Models\AdminReviewItem::create(['user_id'=>$item->user_id,'kind'=>$kind,'record_id'=>$item->id,'payload'=>$item->toArray(),'expires_at'=>$item->updated_at->copy()->addHours(48)]);
            });
        }
    }
    public function down(): void {
        foreach (['admin_review_items','cv_templates','mail_settings'] as $table) Schema::dropIfExists($table);
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('last_seen_at'));
        Schema::table('cv_versions', fn (Blueprint $t) => $t->dropColumn('builder_data'));
        Schema::table('cv_documents', fn (Blueprint $t) => $t->dropColumn('expires_at'));
    }
};
