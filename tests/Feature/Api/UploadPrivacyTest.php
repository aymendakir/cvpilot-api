<?php

namespace Tests\Feature\Api;

use App\Models\CvDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * S6 privacy decision: the original upload is never stored. Its text is extracted from PHP's temp file,
 * the temp file is deleted in a finally block, and only `extracted_text` and metadata are kept (48 hours).
 */
class UploadPrivacyTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    private const CV = "Experience\nBuilt web applications with PHP and Laravel for several clients.\nEducation\nBachelor degree in computer science.";

    private function cv(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('cv.txt', self::CV);
    }

    private function noStoredFiles(): void
    {
        foreach (['local', 'public'] as $disk) {
            $this->assertSame([], Storage::disk($disk)->allFiles(), "{$disk} disk must stay empty");
        }
    }

    public function test_an_upload_keeps_only_text_and_metadata(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $user = $this->makeUser();

        $response = $this->signIn($user)->post('/api/v1/cv-documents', ['file' => $this->cv()], ['Accept' => 'application/json'])->assertStatus(201);

        $doc = CvDocument::findOrFail($response->json('id'));
        $this->assertNull($doc->disk_path);
        $this->assertStringContainsString('Built web applications', $doc->extracted_text);
        $this->assertSame('cv.txt', $doc->name);
        $this->assertSame(strlen(self::CV), $doc->size);
        $this->assertNotNull($doc->expires_at);
        $this->noStoredFiles();
        $this->assertStringNotContainsString('disk_path', $response->getContent());
    }

    public function test_the_temp_file_is_deleted_after_an_accepted_upload(): void
    {
        Storage::fake('local');
        $file = $this->cv();
        $path = $file->getPathname();
        $this->assertFileExists($path);

        $this->signIn($this->makeUser())->post('/api/v1/cv-documents', ['file' => $file], ['Accept' => 'application/json'])->assertStatus(201);

        $this->assertFileDoesNotExist($path);
    }

    public function test_the_temp_file_is_deleted_when_the_file_is_rejected(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->createWithContent('cv.exe', "MZ\x90\x00binary\x00data");
        $path = $file->getPathname();

        $this->signIn($this->makeUser())->post('/api/v1/cv-documents', ['file' => $file], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertFileDoesNotExist($path);
        $this->assertDatabaseCount('cv_documents', 0);
        $this->noStoredFiles();
    }

    public function test_the_stateless_extract_endpoint_also_discards_the_temp_file(): void
    {
        Storage::fake('local');
        $file = $this->cv();
        $path = $file->getPathname();

        $this->signIn($this->makeUser())->post('/api/v1/cv-documents/extract', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('text', fn ($text) => str_contains($text, 'Built web applications'));

        $this->assertFileDoesNotExist($path);
        $this->assertDatabaseCount('cv_documents', 0);
        $this->noStoredFiles();
    }

    public function test_uploading_a_document_stores_no_file(): void
    {
        Storage::fake('local');
        $file = $this->cv();
        $path = $file->getPathname();

        $this->signIn($this->makeUser())->post('/api/v1/cv-documents', ['file' => $file], ['Accept' => 'application/json'])->assertStatus(201);

        $this->assertNull(CvDocument::first()->disk_path);
        $this->assertFileDoesNotExist($path);
        $this->noStoredFiles();
    }

    public function test_a_document_without_a_stored_file_can_be_deleted_and_its_account_removed(): void
    {
        Storage::fake('local');
        $user = $this->makeUser();
        $id = $this->signIn($user)->post('/api/v1/cv-documents', ['file' => $this->cv()], ['Accept' => 'application/json'])->json('id');

        $this->signIn($user)->deleteJson("/api/v1/cv-documents/{$id}")->assertStatus(204);
        $this->assertDatabaseCount('cv_documents', 0);

        $this->signIn($user)->post('/api/v1/cv-documents', ['file' => $this->cv()], ['Accept' => 'application/json'])->assertStatus(201);
        $this->signIn($user)->deleteJson('/api/v1/me', ['current_password' => 'correct-horse-battery', 'confirmation' => 'DELETE'])->assertOk();
        $this->assertDatabaseCount('cv_documents', 0);
    }

    public function test_the_file_path_column_is_nullable(): void
    {
        $this->assertTrue(Schema::hasColumn('cv_documents', 'disk_path'), 'the column stays until a later contract step');

        DB::table('cv_documents')->insert(['user_id' => $this->makeUser()->id, 'name' => 'x', 'disk_path' => null, 'mime' => 'text/plain', 'size' => 1, 'extracted_text' => 't', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertDatabaseCount('cv_documents', 1);
    }

    public function test_the_cleanup_migration_deletes_old_originals_and_clears_the_path(): void
    {
        Storage::fake('local');
        $user = $this->makeUser();
        $mk = fn (string $path) => CvDocument::create(['user_id' => $user->id, 'name' => 'old.pdf', 'disk_path' => $path, 'mime' => 'application/pdf', 'size' => 1, 'extracted_text' => 'kept text', 'expires_at' => now()->addHours(5)]);
        $withFile = $mk("cv/{$user->id}/a.pdf");
        $missingFile = $mk("cv/{$user->id}/gone.pdf");
        Storage::disk('local')->put($withFile->disk_path, 'original bytes');

        (require database_path('migrations/2026_10_05_000012_stop_storing_uploaded_originals.php'))->up();

        Storage::disk('local')->assertMissing("cv/{$user->id}/a.pdf");
        $this->assertNull($withFile->fresh()->disk_path);
        $this->assertNull($missingFile->fresh()->disk_path);
        $this->assertSame('kept text', $withFile->fresh()->extracted_text, 'the extracted text stays');
        $this->assertNotNull($withFile->fresh()->expires_at);
    }
}
