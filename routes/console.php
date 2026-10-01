<?php

use App\Services\TemporaryDataCleanup;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('cvpilot:prune-temporary', function () {
    app(TemporaryDataCleanup::class)();
    $this->info('Expired uploads and admin copies removed. Saved work was preserved.');
});
Schedule::command('cvpilot:prune-temporary')->hourly();
