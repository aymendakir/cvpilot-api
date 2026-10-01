<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /**
     * Uploaded originals are no longer stored (SPEC S6): only extracted text and metadata are kept.
     * Expand step: the column becomes nullable and unused. It is dropped in a later release, because an old
     * instance that is still running during a rolling deploy would otherwise fail when it inserts a path.
     */
    public function up(): void
    {
        Schema::table('cv_documents', function (Blueprint $table) {
            $table->string('disk_path')->nullable()->change();
        });

        // Delete originals that are still on a disk (local or compose volumes; gone anyway on ephemeral hosts).
        DB::table('cv_documents')->whereNotNull('disk_path')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                try {
                    Storage::disk('local')->delete($row->disk_path);
                } catch (Throwable) {
                    // A file that cannot be removed must not block the migration; the row is cleaned either way.
                }
            }
            DB::table('cv_documents')->whereIn('id', $rows->pluck('id'))->update(['disk_path' => null]);
        });
    }

    public function down(): void
    {
        // Deleted originals cannot be restored; the column stays nullable.
    }
};
