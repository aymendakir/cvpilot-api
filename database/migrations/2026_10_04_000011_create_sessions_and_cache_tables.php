<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Production keeps sessions and cache in the database: the host has no persistent disk (SPEC S6).
     * Repeatable: each table is created only if missing and each secondary index is added only if missing
     * (see the blog migration for why).
     */
    public function up(): void
    {
        if (! Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->foreignId('user_id')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity');
            });
        }
        $this->indexIfMissing('sessions', 'sessions_user_id_index', fn (Blueprint $table) => $table->index('user_id'));
        $this->indexIfMissing('sessions', 'sessions_last_activity_index', fn (Blueprint $table) => $table->index('last_activity'));

        if (! Schema::hasTable('cache')) {
            Schema::create('cache', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration');
            });
        }
        $this->indexIfMissing('cache', 'cache_expiration_index', fn (Blueprint $table) => $table->index('expiration'));

        if (! Schema::hasTable('cache_locks')) {
            Schema::create('cache_locks', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->string('owner');
                $table->integer('expiration');
            });
        }
        $this->indexIfMissing('cache_locks', 'cache_locks_expiration_index', fn (Blueprint $table) => $table->index('expiration'));
    }

    public function down(): void
    {
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('sessions');
    }

    private function indexIfMissing(string $table, string $index, Closure $add): void
    {
        if (! Schema::hasIndex($table, $index)) {
            Schema::table($table, $add);
        }
    }
};
