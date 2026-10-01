<?php

namespace App\Services;

use App\Models\AdminReviewItem;
use Illuminate\Database\Eloquent\Model;

class AdminReview
{
    /**
     * Kinds that are copied for admins. CV-bearing records (cv, workspace, interview, report) are
     * never copied: admins do not read user CV text (SPEC S4). Only applications are read, by `admin/applications`.
     */
    public const RECORDED_KINDS = ['application'];

    public static function record(string $kind, Model $record): void
    {
        if (! in_array($kind, self::RECORDED_KINDS, true)) {
            return;
        }

        AdminReviewItem::updateOrCreate(['kind' => $kind, 'record_id' => $record->id], [
            'user_id' => $record->user_id, 'payload' => $record->toArray(), 'expires_at' => now()->addHours(48),
        ]);
    }

    public static function forget(string $kind, int $id): void
    {
        AdminReviewItem::where('kind', $kind)->where('record_id', $id)->delete();
    }
}
