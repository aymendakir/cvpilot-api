<?php

namespace App\Http\Controllers\Api\V1\Admin;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;

class CacheController
{
    /** v1: 204 No Content. */
    public function destroy(): Response
    {
        Artisan::call('cache:clear');

        return response()->noContent();
    }
}
