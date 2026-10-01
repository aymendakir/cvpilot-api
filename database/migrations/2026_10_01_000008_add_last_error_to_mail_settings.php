<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Redacted reason of the last failed connection check, test email or Microsoft connect (like integrations.last_error).
        // Repeatable: a run that died after adding the column but before being recorded must not fail the next start.
        if (Schema::hasColumn('mail_settings', 'last_error')) {
            return;
        }

        Schema::table('mail_settings', function (Blueprint $table) {
            $table->text('last_error')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('mail_settings', 'last_error')) {
            Schema::table('mail_settings', fn (Blueprint $table) => $table->dropColumn('last_error'));
        }
    }
};
