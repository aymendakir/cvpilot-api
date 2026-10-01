<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * POST /api/v1/ats/analyses refusals (SPEC-ats.md §5.1, §8.4): 422 validation_failed with the field in
 * `errors`; `errors.file` holds a reason token (S4 decision 29); no internals in any body.
 */
class AtsAnalysisErrorsTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    private const DIR = __DIR__.'/../../fixtures/ats';

    private const CV = 'Backend developer with seven years of PHP and Laravel experience in Rabat.';

    private function fixture(string $path, string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, (string) file_get_contents(self::DIR."/{$path}"));
    }

    private function refused(array $body, string $field)
    {
        $response = $this->signIn($this->makeUser())->post('/api/v1/ats/analyses', $body, ['Accept' => 'application/json']);
        $response->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonStructure(['message', 'code', 'errors' => [$field], 'request_id']);
        $raw = (string) $response->getContent();
        foreach (['/tmp', '/home', 'vendor', 'Exception', 'App\\\\', '.php', 'poppler', 'stack'] as $leak) {
            $this->assertStringNotContainsString($leak, $raw, "no internals in the body ({$leak})");
        }

        return $response;
    }

    /** @return array<string, array{0: callable(self): UploadedFile, 1: string}> */
    public static function badFiles(): array
    {
        return [
            'password-protected PDF' => [fn (self $t) => $t->fixture('invalid/encrypted.pdf', 'cv.pdf'), 'password_protected'],
            'corrupt PDF' => [fn (self $t) => $t->fixture('invalid/corrupt.pdf', 'cv.pdf'), 'corrupt'],
            'Word 97 .doc' => [fn (self $t) => $t->fixture('invalid/legacy.doc', 'cv.doc'), 'unsupported_type'],
            'executable renamed .pdf' => [fn (self $t) => $t->fixture('invalid/renamed-exe.pdf', 'cv.pdf'), 'unsupported_type'],
            'plain-text upload' => [fn (self $t) => UploadedFile::fake()->createWithContent('cv.txt', str_repeat(self::CV.' ', 10)), 'unsupported_type'],
            'image upload' => [fn (self $t) => UploadedFile::fake()->image('cv.png', 400, 600), 'unsupported_type'],
            '16 MB file' => [fn (self $t) => UploadedFile::fake()->create('cv.pdf', 16 * 1024, 'application/pdf'), 'too_large'],
        ];
    }

    #[DataProvider('badFiles')]
    public function test_bad_files_are_refused_with_a_reason_token(callable $file, string $reason): void
    {
        $response = $this->refused(['file' => $file($this)], 'file');

        $this->assertSame([$reason], $response->json('errors.file'));
    }

    public function test_exactly_one_of_file_and_cv_text(): void
    {
        $neither = $this->refused([], 'file');
        $this->assertSame(['missing'], $neither->json('errors.file'));
        $this->assertArrayHasKey('cv_text', $neither->json('errors'));

        $both = $this->refused(['file' => $this->fixture('cvs/clean-en.docx', 'cv.docx'), 'cv_text' => self::CV], 'file');
        $this->assertSame(['both_given'], $both->json('errors.file'));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function badFields(): array
    {
        return [
            'cv_text under 30 characters' => [['cv_text' => 'Too short to be a CV.'], 'cv_text'],
            'cv_text over 30 000 characters' => [['cv_text' => str_repeat('a', 30001)], 'cv_text'],
            'cv_text not a string' => [['cv_text' => ['a', 'b']], 'cv_text'],
            'job_description under 60 characters' => [['cv_text' => self::CV, 'job_description' => 'PHP developer wanted.'], 'job_description'],
            'job_description over 30 000 characters' => [['cv_text' => self::CV, 'job_description' => str_repeat('b', 30001)], 'job_description'],
            'unknown locale' => [['cv_text' => self::CV, 'locale' => 'de'], 'locale'],
            'include_text not a boolean' => [['cv_text' => self::CV, 'include_text' => 'maybe'], 'include_text'],
        ];
    }

    #[DataProvider('badFields')]
    public function test_bad_fields_name_the_field(array $body, string $field): void
    {
        $response = $this->refused($body, $field);

        $this->assertSame([$field], array_keys($response->json('errors')));
    }

    public function test_limits_are_inclusive(): void
    {
        $this->signIn($this->makeUser());
        $this->post('/api/v1/ats/analyses', ['cv_text' => str_repeat('x', 30)], ['Accept' => 'application/json'])->assertOk();
        $this->post('/api/v1/ats/analyses', ['cv_text' => self::CV, 'job_description' => str_repeat('y', 60)], ['Accept' => 'application/json'])->assertOk();
    }
}
