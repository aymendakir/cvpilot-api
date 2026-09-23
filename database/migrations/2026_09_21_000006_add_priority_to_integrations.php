<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Integration;

return new class extends Migration {
    public function up(): void {
        if (!Schema::hasColumn('integrations', 'priority')) {
            Schema::table('integrations', function (Blueprint $table) {
                $table->unsignedInteger('priority')->default(1)->after('enabled')->index();
            });
        }

        // Set sequential default priorities for existing rows
        $aiItems = Integration::where('type', 'ai')->orderBy('id')->get();
        $aiIndex = 1;
        foreach ($aiItems as $item) {
            $item->priority = $aiIndex++;
            $item->save();
        }

        $jobItems = Integration::where('type', 'jobs')->orderBy('id')->get();
        $jobIndex = 1;
        foreach ($jobItems as $item) {
            $item->priority = $jobIndex++;
            $item->save();
        }
    }

    public function down(): void {
        if (Schema::hasColumn('integrations', 'priority')) {
            Schema::table('integrations', function (Blueprint $table) {
                $table->dropColumn('priority');
            });
        }
    }
};
