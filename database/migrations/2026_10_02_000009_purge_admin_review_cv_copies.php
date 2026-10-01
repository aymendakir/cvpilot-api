<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Admin copies of CV-bearing records are no longer written (SPEC S4); delete the ones that exist. */
    public function up(): void
    {
        DB::table('admin_review_items')->whereIn('kind', ['cv', 'workspace', 'interview', 'report'])->delete();
    }

    public function down(): void
    {
        // The deleted copies were temporary (48 hours) and cannot be restored.
    }
};
