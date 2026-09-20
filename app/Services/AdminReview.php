<?php
namespace App\Services;
use App\Models\AdminReviewItem;
use Illuminate\Database\Eloquent\Model;

class AdminReview {
    public static function record(string $kind, Model $record): void {
        AdminReviewItem::updateOrCreate(['kind'=>$kind,'record_id'=>$record->id], [
            'user_id'=>$record->user_id, 'payload'=>$record->toArray(), 'expires_at'=>now()->addHours(48),
        ]);
    }
    public static function forget(string $kind, int $id): void {
        AdminReviewItem::where('kind',$kind)->where('record_id',$id)->delete();
    }
}
