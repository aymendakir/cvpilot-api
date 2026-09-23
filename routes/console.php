<?php
use Illuminate\Support\Facades\{Artisan,Schedule};
Artisan::command('cvpilot:prune-temporary', function () { app(App\Services\TemporaryDataCleanup::class)(); $this->info('Expired uploads and admin copies removed. Saved work was preserved.'); });
Schedule::command('cvpilot:prune-temporary')->hourly();
