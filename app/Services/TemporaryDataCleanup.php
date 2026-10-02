<?php

namespace App\Services;

use App\Models\AdminReviewItem;
use App\Models\CvDocument;
use App\Models\SupportMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TemporaryDataCleanup
{
    public function __invoke(): void
    {
        CvDocument::where('expires_at', '<=', now())->chunkById(100, function ($docs) {
            foreach ($docs as $doc) {
                $disk = Storage::disk('local');
                if ($doc->disk_path && $disk->exists($doc->disk_path) && ! $disk->delete($doc->disk_path)) {
                    throw new \RuntimeException('Could not remove expired upload.');
                }
                $doc->delete();
            }
        });
        SupportMessage::where('created_at', '<=', now()->subDays(90))->delete();
        AdminReviewItem::where('expires_at', '<=', now())->delete();
        $this->pruneExpiredCache();
    }

    /**
     * With the database cache store (production on Sevalla), expired entries stay as rows until the same key
     * is written again. The anonymous routes (API-A) add rate-limit counters per hashed visitor, so expired
     * rows are removed here.
     */
    private function pruneExpiredCache(): void
    {
        if (config('cache.default') !== 'database') {
            return;
        }
        $store = config('cache.stores.database');
        DB::connection($store['connection'] ?? null)->table($store['table'] ?? 'cache')
            ->where('expiration', '<=', now()->getTimestamp())
            ->delete();
    }
}
