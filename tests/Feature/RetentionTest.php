<?php

namespace Tests\Feature;

use App\Models\AdminReviewItem;
use App\Models\Application;
use App\Models\CvDocument;
use App\Models\CvVersion;
use App\Models\SupportMessage;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * SPEC §7 item 6: the 48-hour promise is kept by the scheduler, not only by a command somebody has to remember.
 * `schedule:run` starts a child process, so the test finds the scheduled command and runs that same command in-process.
 */
class RetentionTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    private function pruneEvent(): Event
    {
        foreach (app(Schedule::class)->events() as $event) {
            if (str_contains((string) $event->command, 'cvpilot:prune-temporary')) {
                return $event;
            }
        }

        $this->fail('cvpilot:prune-temporary is not scheduled');
    }

    /** Runs the artisan command exactly as the scheduler would invoke it. */
    private function runScheduledPrune(): void
    {
        preg_match('/artisan[\'"]?\s+(\S+)/', (string) $this->pruneEvent()->command, $m);

        $this->assertSame(0, Artisan::call($m[1]));
    }

    public function test_the_prune_command_is_scheduled_hourly_without_overlap(): void
    {
        $event = $this->pruneEvent();

        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping, 'a slow run must not start a second one');
    }

    public function test_the_scheduled_command_deletes_expired_uploads_with_their_files_and_keeps_the_rest(): void
    {
        Storage::fake('local');
        $user = $this->makeUser();
        $make = fn (string $name, $expires) => tap(CvDocument::create([
            'user_id' => $user->id, 'name' => $name, 'disk_path' => "cv/{$user->id}/{$name}", 'mime' => 'application/pdf', 'size' => 1,
            'extracted_text' => 'text', 'expires_at' => $expires,
        ]), fn (CvDocument $doc) => Storage::disk('local')->put($doc->disk_path, 'file'));
        $expired = $make('expired.pdf', now()->subMinute());
        $alive = $make('alive.pdf', now()->addHours(47));

        $this->runScheduledPrune();

        $this->assertNull(CvDocument::find($expired->id));
        Storage::disk('local')->assertMissing($expired->disk_path);
        $this->assertNotNull(CvDocument::find($alive->id));
        Storage::disk('local')->assertExists($alive->disk_path);
    }

    public function test_an_expired_row_whose_file_is_already_gone_is_still_removed(): void
    {
        Storage::fake('local');
        $user = $this->makeUser();
        // What a redeploy leaves behind on a host without a persistent disk: the row, no file.
        $orphan = CvDocument::create(['user_id' => $user->id, 'name' => 'orphan.pdf', 'disk_path' => 'cv/1/gone.pdf', 'mime' => 'application/pdf', 'size' => 1, 'extracted_text' => 't', 'expires_at' => now()->subHour()]);

        $this->runScheduledPrune();

        $this->assertNull(CvDocument::find($orphan->id));
    }

    public function test_the_scheduled_command_removes_expired_admin_copies_and_old_support_messages_only(): void
    {
        $user = $this->makeUser();
        $copy = fn (int $id, $expires) => AdminReviewItem::create(['user_id' => $user->id, 'kind' => 'application', 'record_id' => $id, 'payload' => ['x' => 1], 'expires_at' => $expires]);
        $oldCopy = $copy(1, now()->subMinute());
        $freshCopy = $copy(2, now()->addHours(2));
        $message = fn (string $created) => tap(SupportMessage::create(['name' => 'A', 'email' => 'a@example.test', 'topic' => 'feedback', 'message' => str_repeat('x', 30), 'status' => 'new']), fn ($m) => $m->forceFill(['created_at' => $created])->save());
        $old = $message(now()->subDays(91)->toDateTimeString());
        $fresh = $message(now()->subDays(89)->toDateTimeString());

        $this->runScheduledPrune();

        $this->assertNull(AdminReviewItem::find($oldCopy->id));
        $this->assertNotNull(AdminReviewItem::find($freshCopy->id));
        $this->assertNull(SupportMessage::find($old->id));
        $this->assertNotNull(SupportMessage::find($fresh->id));
    }

    public function test_saved_work_and_accounts_are_never_pruned(): void
    {
        $user = $this->makeUser();
        $version = CvVersion::create(['user_id' => $user->id, 'name' => 'CV', 'content' => str_repeat('x', 40), 'source' => 'manual']);
        $application = Application::create(['user_id' => $user->id, 'title' => 'Dev', 'company' => 'Acme', 'url' => 'https://example.com/j']);
        $this->travel(400)->days();

        $this->runScheduledPrune();

        $this->assertNotNull($version->fresh());
        $this->assertNotNull($application->fresh());
        $this->assertNotNull($user->fresh());
    }

    public function test_each_run_leaves_a_heartbeat(): void
    {
        $this->assertNull(Cache::get('retention:last_run_at'));

        $this->runScheduledPrune();

        $this->assertEqualsWithDelta(now()->timestamp, strtotime((string) Cache::get('retention:last_run_at')), 5);
    }

    public function test_the_command_exists_and_the_scheduler_lists_it(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('cvpilot:prune-temporary')->assertSuccessful();
    }
}
