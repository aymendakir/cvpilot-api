<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Production incident: MySQL DDL is not transactional, so a migration that dies between its statements leaves a
 * half-built table and no migrations row; the next start then fails with "table already exists" forever.
 * Every migration added since S3 must therefore be safe to run again on whatever a previous run left behind.
 */
class MigrationIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private const BLOG = '2026_10_03_000010_create_blog_posts_table';

    private const STATE = '2026_10_04_000011_create_sessions_and_cache_tables';

    private function migration(string $name): object
    {
        return require database_path("migrations/{$name}.php");
    }

    /** @return array<int, string> */
    private function recentMigrations(): array
    {
        $names = array_map(fn ($file) => basename($file, '.php'), glob(database_path('migrations/2026_10_*.php')));
        sort($names);

        return $names;
    }

    public function test_every_recent_migration_can_run_again_on_a_fully_migrated_database(): void
    {
        $names = $this->recentMigrations();
        $this->assertGreaterThanOrEqual(5, count($names), 'the S3-S6 migrations should be found');

        foreach ($names as $name) {
            $this->migration($name)->up();
            $this->migration($name)->up();
        }

        $this->assertTrue(Schema::hasTable('blog_posts'));
    }

    public function test_the_blog_migration_completes_on_a_half_built_table_and_records_itself(): void
    {
        // What production had: the table exists, the unique slug index and the status index do not, and nothing is recorded.
        Schema::dropIfExists('blog_posts');
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
        DB::table('migrations')->where('migration', self::BLOG)->delete();
        $this->assertFalse(Schema::hasIndex('blog_posts', 'blog_posts_slug_unique'));

        $this->artisan('migrate', ['--force' => true])->assertSuccessful();

        $this->assertTrue(Schema::hasIndex('blog_posts', 'blog_posts_slug_unique'), 'the unique slug index is added');
        $this->assertTrue(Schema::hasIndex('blog_posts', 'blog_posts_status_published_at_index'), 'the status index is added');
        $this->assertTrue(DB::table('migrations')->where('migration', self::BLOG)->exists(), 'the migration records itself');

        // A second start does nothing and does not fail.
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    }

    public function test_the_unique_slug_index_really_is_enforced_after_the_repair(): void
    {
        Schema::dropIfExists('blog_posts');
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
        $this->migration(self::BLOG)->up();
        $row = ['title' => 't', 'slug' => 'same', 'excerpt' => 'e', 'body' => 'b', 'author_name' => 'a', 'created_at' => now(), 'updated_at' => now()];
        DB::table('blog_posts')->insert($row);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('blog_posts')->insert($row);
    }

    public function test_the_blog_migration_creates_everything_on_a_missing_table(): void
    {
        Schema::dropIfExists('blog_posts');

        $this->migration(self::BLOG)->up();

        $this->assertTrue(Schema::hasIndex('blog_posts', 'blog_posts_slug_unique'));
        $this->assertTrue(Schema::hasIndex('blog_posts', 'blog_posts_status_published_at_index'));
        $this->assertSame(['id', 'title', 'slug', 'excerpt', 'body', 'author_name', 'status', 'published_at', 'created_at', 'updated_at'], Schema::getColumnListing('blog_posts'));
    }

    public function test_the_sessions_and_cache_migration_completes_partial_tables(): void
    {
        foreach (['sessions', 'cache', 'cache_locks'] as $table) {
            Schema::dropIfExists($table);
        }
        // Only the first table was created before the previous run died, and without its secondary indexes.
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity');
        });

        $this->migration(self::STATE)->up();

        foreach (['sessions', 'cache', 'cache_locks'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
        $this->assertTrue(Schema::hasIndex('sessions', 'sessions_user_id_index'));
        $this->assertTrue(Schema::hasIndex('sessions', 'sessions_last_activity_index'));
        $this->assertTrue(Schema::hasIndex('cache', 'cache_expiration_index'));
        $this->assertTrue(Schema::hasIndex('cache_locks', 'cache_locks_expiration_index'));
    }

    public function test_the_last_error_column_is_not_added_twice(): void
    {
        $this->assertTrue(Schema::hasColumn('mail_settings', 'last_error'));

        $this->migration('2026_10_01_000008_add_last_error_to_mail_settings')->up();

        $this->assertSame(1, count(array_filter(Schema::getColumnListing('mail_settings'), fn ($c) => $c === 'last_error')));
    }

    public function test_the_upload_cleanup_migration_is_repeatable(): void
    {
        $name = '2026_10_05_000012_stop_storing_uploaded_originals';

        $this->migration($name)->up();
        $this->migration($name)->up();

        $disk = collect(Schema::getColumns('cv_documents'))->firstWhere('name', 'disk_path');
        $this->assertTrue($disk['nullable']);
    }

    public function test_migrate_rebuilds_the_tables_from_nothing_and_records_the_migrations(): void
    {
        foreach (['blog_posts', 'sessions', 'cache', 'cache_locks'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::table('migrations')->whereIn('migration', [self::BLOG, self::STATE])->delete();

        $this->artisan('migrate', ['--force' => true])->assertSuccessful();

        foreach (['blog_posts', 'sessions', 'cache', 'cache_locks'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
        $this->assertTrue(Schema::hasIndex('blog_posts', 'blog_posts_slug_unique'));
        $this->assertSame(2, DB::table('migrations')->whereIn('migration', [self::BLOG, self::STATE])->count());
    }
}
