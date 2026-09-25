<?php
namespace App\Services;
use App\Models\{CvDocument,AdminReviewItem};
use Illuminate\Support\Facades\Storage;

class TemporaryDataCleanup {
    public function __invoke(): void {
        CvDocument::where('expires_at','<=',now())->chunkById(100,function ($docs) {
            foreach ($docs as $doc) {
                $disk=Storage::disk('local');
                if ($disk->exists($doc->disk_path) && !$disk->delete($doc->disk_path)) throw new \RuntimeException('Could not remove expired upload.');
                $doc->delete();
            }
        });
        \App\Models\SupportMessage::where('created_at', '<=', now()->subDays(90))->delete();
        AdminReviewItem::where('expires_at','<=',now())->delete();
    }
}
