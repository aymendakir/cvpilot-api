<?php
use Illuminate\Support\Facades\Artisan;
Artisan::command('cvpilot:prune-temporary', function () { app(App\Services\TemporaryDataCleanup::class)(); $this->info('Expired uploads and admin copies removed. Saved work was preserved.'); });
