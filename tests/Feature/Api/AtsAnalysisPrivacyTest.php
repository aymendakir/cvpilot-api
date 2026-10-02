<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * POST /api/v1/ats/analyses is stateless and private (SPEC-ats.md §8.4): same input → same body except
 * `generated_at`; no file left behind; no CV or job text, file name or score in the logs (one content-
 * free line per analysis, S4 decision 30); no row stored; time budgets.
 */
class AtsAnalysisPrivacyTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    private const DIR = __DIR__.'/../../fixtures/ats';

    private const URL = '/api/v1/ats/analyses';

    private const SENTINEL = 'Zq7Sentinel4471';

    private function analyse(array $body)
    {
        return $this->post(self::URL, $body, ['Accept' => 'application/json']);
    }

    public function test_the_same_request_gives_the_same_body_except_generated_at(): void
    {
        $this->signIn($this->makeUser());
        foreach ([['cvs/two-column.pdf', 'jobs/laravel-dev-short.txt'], ['cvs/clean-fr.docx', null], ['cvs/no-email-no-exp.txt', 'jobs/laravel-dev.txt']] as [$cv, $job]) {
            $a = $this->analyse(AtsAnalysesTest::body($cv, $job))->assertOk()->json();
            $b = $this->analyse(AtsAnalysesTest::body($cv, $job))->assertOk()->json();
            unset($a['generated_at'], $b['generated_at']);
            $this->assertSame($a, $b, $cv);
        }
    }

    /** @return list<string> top level of the system temp dir (see ParsingFixturesTest) and storage/ without logs and framework */
    private function files(): array
    {
        $files = array_map(fn ($name) => sys_get_temp_dir().'/'.$name, array_diff(scandir(sys_get_temp_dir()) ?: [], ['.', '..']));
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(storage_path(), \FilesystemIterator::SKIP_DOTS)) as $f) {
            if (! preg_match('#/storage/(logs|framework)/#', $f->getPathname())) {
                $files[] = $f->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /** @return array<string, int> rows per table, except the session and the rate-limiter cache */
    private function rows(): array
    {
        $rows = [];
        foreach (DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'") as $table) {
            if (! in_array($table->name, ['sessions', 'cache', 'cache_locks'], true)) {
                $rows[$table->name] = DB::table($table->name)->count();
            }
        }

        return $rows;
    }

    public function test_nothing_is_kept_and_no_content_is_logged(): void
    {
        $this->signIn($this->makeUser());
        $cvText = "Samir Benali\n".self::SENTINEL."@example.com\nWork Experience\nBuilt Laravel APIs used by ".self::SENTINEL." customers.\n".str_repeat('Reduced costs by 20% with careful PHP work. ', 10);
        $job = "Requirements:\nPHP\nLaravel\n".self::SENTINEL."\nDocker\nNice to have:\nRedis and a team that ships every week.";
        $bodies = [
            ['cv_text' => $cvText, 'job_description' => $job],
            ['file' => UploadedFile::fake()->createWithContent(self::SENTINEL.'-cv.docx', (string) file_get_contents(self::DIR.'/cvs/clean-en.docx')), 'job_description' => $job],
            ['file' => UploadedFile::fake()->createWithContent(self::SENTINEL.'-cv.pdf', (string) file_get_contents(self::DIR.'/cvs/two-column.pdf'))],
            ['file' => UploadedFile::fake()->createWithContent(self::SENTINEL.'-locked.pdf', (string) file_get_contents(self::DIR.'/invalid/encrypted.pdf'))],
        ];
        // Production may run at LOG_LEVEL=info, where the analysis line is written: capture at that level.
        $channel = (string) config('logging.default');
        config(["logging.channels.{$channel}.level" => 'info']);
        Log::forgetChannel($channel);
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = ['message' => $e->message, 'context' => $e->context];
        });
        $files = $this->files();
        $rows = $this->rows();

        foreach ($bodies as $i => $body) {
            $this->analyse($body)->assertStatus($i === 3 ? 422 : 200);
        }

        $this->assertSame($files, $this->files(), 'no file left in the temp dir or storage/');
        $this->assertSame($rows, $this->rows(), 'no row stored');
        $all = (string) json_encode($logged, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString(self::SENTINEL, $all, 'no CV text, job text or file name in the logs');
        $lines = array_values(array_filter($logged, fn ($l) => $l['message'] === 'ats.analysis'));
        $this->assertCount(4, $lines, 'one line per analysis');
        $this->assertSame(['outcome', 'mode', 'type', 'pages', 'duration_ms', 'access'], array_keys($lines[0]['context']));
        $this->assertSame('account', $lines[0]['context']['access']);
        $this->assertSame(['outcome' => 'refused', 'reason' => 'password_protected'], array_slice($lines[3]['context'], 0, 2));
        foreach ($lines as $line) {
            $this->assertArrayNotHasKey('score', $line['context']);
        }
    }

    public function test_clean_cvs_are_analysed_within_the_time_budget(): void
    {
        $this->signIn($this->makeUser());
        foreach (['cvs/clean-en.docx', 'cvs/clean-en.pdf'] as $cv) {
            $body = AtsAnalysesTest::body($cv, 'jobs/laravel-dev.txt');
            $started = hrtime(true);
            $this->analyse($body)->assertOk();
            $this->assertLessThan(1.5, (hrtime(true) - $started) / 1e9, "{$cv}: §8.4 budget (1.5 s)");
        }
    }

    public function test_a_worst_case_pdf_near_the_size_limit_stays_under_ten_seconds(): void
    {
        $pdf = self::worstCasePdf();
        $this->assertGreaterThan(13 * 1048576, strlen($pdf));
        $this->assertLessThan(15 * 1048576, strlen($pdf));
        $this->signIn($this->makeUser());

        $started = hrtime(true);
        $response = $this->analyse(['file' => UploadedFile::fake()->createWithContent('large.pdf', $pdf), 'job_description' => (string) file_get_contents(self::DIR.'/jobs/laravel-dev.txt')]);
        $seconds = (hrtime(true) - $started) / 1e9;

        $response->assertOk();
        $this->assertSame(40, $response->json('document.pages'));
        $this->assertSame('scored', $response->json('score_status'));
        $this->assertLessThan(10, $seconds, '§8.4: 15 MB worst case ≤ 10 s');
    }

    /** ~14 MB PDF: 40 pages of CV-like text and one large uncompressed photo on page 1 (written by hand, not committed). */
    public static function worstCasePdf(): string
    {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $side = 2150;
        $pixels = random_bytes($side * $side * 3);
        $objects[4] = "<< /Type /XObject /Subtype /Image /Width {$side} /Height {$side} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Length ".strlen($pixels)." >>\nstream\n{$pixels}\nendstream";
        $kids = [];
        $next = 5;
        for ($page = 1; $page <= 40; $page++) {
            $text = "BT /F1 10 Tf 12 TL 50 800 Td\n";
            for ($line = 1; $line <= 60; $line++) {
                $text .= "(Page {$page} line {$line}: built and maintained Laravel services, reduced costs by 12% for clients.) '\n";
            }
            $text .= "ET\n";
            if ($page === 1) {
                $text = "q 200 0 0 200 360 600 cm /Im1 Do Q\n".$text;
            }
            $contentId = $next++;
            $pageId = $next++;
            $objects[$contentId] = '<< /Length '.strlen($text)." >>\nstream\n{$text}endstream";
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> /XObject << /Im1 4 0 R >> >> /Contents {$contentId} 0 R >>";
            $kids[] = "{$pageId} 0 R";
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count 40 >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref
0 '.(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
