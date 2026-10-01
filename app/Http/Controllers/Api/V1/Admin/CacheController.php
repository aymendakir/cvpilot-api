<?php

namespace App\Http\Controllers\Api\V1\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;

class CacheController
{
    /** Legacy response (POST admin/cache/clear): 200 with a message. Removed with the legacy aliases. */
    public function clear(): JsonResponse
    {
        Artisan::call('cache:clear');

        return response()->json(['message' => 'System cache cleared successfully.']);
    }
}
