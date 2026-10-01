<?php

use App\Http\Controllers\ConsoleController;
use Illuminate\Support\Facades\Route;

// The API lives in routes/api.php (/api/v1) and routes/legacy.php (deprecated aliases).
// The health route (/up) is registered in bootstrap/app.php.
Route::get('/', ConsoleController::class)->name('console');
