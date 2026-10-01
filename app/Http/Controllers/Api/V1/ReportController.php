<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\CareerReport;
use App\Services\AdminReview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ReportController
{
    public function destroy(Request $r, CareerReport $report)
    {
        Gate::forUser($r->user())->authorize('delete', $report);
        AdminReview::forget('report', $report->id);
        $report->delete();

        return response()->noContent();
    }
}
