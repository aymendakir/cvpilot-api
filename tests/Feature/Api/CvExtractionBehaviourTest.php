<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesUsers;
use Tests\Feature\Ats\FixturesTest;
use Tests\TestCase;

/**
 * Pins what `POST cv-documents/extract` and `POST cv-documents` return for PDFs, written while the
 * extractor still used smalot/pdfparser, so the switch to poppler (Phase 3 S1, T6) cannot change it:
 * the same words come back, and every refusal keeps its status, code and message.
 */
class CvExtractionBehaviourTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    private const DIR = __DIR__.'/../../fixtures/ats';

    private function upload(string $fixture, string $name = 'cv.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, (string) file_get_contents(self::DIR.'/'.$fixture));
    }

    /** @return array<string, array{0: string}> */
    public static function readablePdfs(): array
    {
        return [
            'single column' => ['cvs/clean-en.pdf'],
            'two columns' => ['cvs/two-column.pdf'],
            'ruled tables' => ['cvs/table-layout.pdf'],
        ];
    }

    #[DataProvider('readablePdfs')]
    public function test_a_readable_pdf_returns_the_same_words(string $fixture): void
    {
        $text = $this->signIn($this->makeUser())
            ->post('/api/v1/cv-documents/extract', ['file' => $this->upload($fixture)], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('text');

        $reference = $fixture === 'cvs/table-layout.pdf'
            ? (string) shell_exec('pdftotext -enc UTF-8 '.escapeshellarg(self::DIR.'/'.$fixture).' -')
            : FixturesTest::docxText('clean-en.docx');
        $this->assertSame(FixturesTest::vocabulary($reference), FixturesTest::vocabulary($text));
        $this->assertStringNotContainsString("\n\n\n", $text, 'blank lines are collapsed');
        $this->assertSame($text, trim($text));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function refusedPdfs(): array
    {
        return [
            'password protected' => ['invalid/encrypted.pdf', 'This PDF is password protected. Upload an unlocked copy.'],
            'corrupt' => ['invalid/corrupt.pdf', 'This document could not be read. Export a fresh text-based PDF or DOCX and try again.'],
            'scanned (no text)' => ['cvs/scanned.pdf', 'No readable CV text found. Scanned PDFs require OCR before upload.'],
            'exe renamed to pdf' => ['invalid/renamed-exe.pdf', 'Unsupported document. Upload a PDF, DOCX or TXT file.'],
        ];
    }

    #[DataProvider('refusedPdfs')]
    public function test_refused_pdfs_keep_status_code_and_message(string $fixture, string $message): void
    {
        foreach (['/api/v1/cv-documents/extract', '/api/v1/cv-documents'] as $url) {
            $response = $this->signIn($this->makeUser())->post($url, ['file' => $this->upload($fixture)], ['Accept' => 'application/json']);

            $response->assertStatus(422);
            $this->assertSame($message, $response->json('message'), $url);
            $this->assertSame('validation_failed', $response->json('code'), $url);
        }
        $this->assertDatabaseCount('cv_documents', 0);
    }

    public function test_a_missing_poppler_binary_is_a_server_error_not_a_bad_file(): void
    {
        // New in S1: smalot failures were all reported as an unreadable file; a missing poppler binary is
        // the server's fault, so it is a 500 (logged with the request id), never blamed on the CV.
        config(['ats.poppler.pdfinfo' => '/nonexistent/pdfinfo']);

        $this->signIn($this->makeUser())
            ->post('/api/v1/cv-documents/extract', ['file' => $this->upload('cvs/clean-en.pdf')], ['Accept' => 'application/json'])
            ->assertStatus(500)
            ->assertJsonPath('code', 'server_error');
    }
}
