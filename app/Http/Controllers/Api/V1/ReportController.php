<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\CareerReport;
use App\Services\AdminReview;
use Illuminate\Http\Request;

class ReportController
{
    public function destroy(Request $r, CareerReport $report)
    {
        abort_unless($report->user_id === $r->user()->id, 404);
        AdminReview::forget('report', $report->id);
        $report->delete();

        return response()->noContent();
    }
}
