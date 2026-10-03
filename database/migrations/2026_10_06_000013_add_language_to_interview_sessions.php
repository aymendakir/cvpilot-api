<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The language a mock interview asks and answers in (plan API-C); null keeps the prompts as they were.
        // Repeatable: a run that died after adding the column but before being recorded must not fail the next start.
        if (Schema::hasColumn('interview_sessions', 'language')) {
            return;
        }

        Schema::table('interview_sessions', function (Blueprint $table) {
            $table->string('language', 16)->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('interview_sessions', 'language')) {
            Schema::table('interview_sessions', fn (Blueprint $table) => $table->dropColumn('language'));
        }
    }
};
