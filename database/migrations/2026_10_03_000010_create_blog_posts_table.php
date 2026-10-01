<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Safe to run again on whatever a previous run left behind. MySQL DDL is not transactional: if a run dies
     * after CREATE TABLE but before the indexes (or before the migrations row is written), a plain create would
     * fail with "table already exists" on every later start. So: create the table only if missing, then add
     * each index only if missing.
     */
    public function up(): void
    {
        if (! Schema::hasTable('blog_posts')) {
            Schema::create('blog_posts', function (Blueprint $table) {
                $table->id();
                $table->string('title', 180);
                $table->string('slug', 191);
                $table->string('excerpt', 500);
                $table->text('body');
                $table->string('author_name', 120);
                $table->string('status', 20)->default('draft');
                $table->timestamp('published_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasIndex('blog_posts', 'blog_posts_slug_unique')) {
            Schema::table('blog_posts', fn (Blueprint $table) => $table->unique('slug'));
        }
        if (! Schema::hasIndex('blog_posts', 'blog_posts_status_published_at_index')) {
            Schema::table('blog_posts', fn (Blueprint $table) => $table->index(['status', 'published_at']));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_posts');
    }
};
