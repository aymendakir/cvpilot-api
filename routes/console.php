<?php

use App\Services\TemporaryDataCleanup;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('cvpilot:prune-temporary', function () {
    app(TemporaryDataCleanup::class)();
    // Proof for admins (admin/system) that the scheduler is running; kept in the cache store.
    Cache::forever('retention:last_run_at', now()->toIso8601String());
    $this->info('Expired uploads and admin copies removed. Saved work was preserved.');
});
Schedule::command('cvpilot:prune-temporary')->hourly()->withoutOverlapping()
    ->onFailure(fn () => Log::error('Scheduled retention run failed (cvpilot:prune-temporary).'));
