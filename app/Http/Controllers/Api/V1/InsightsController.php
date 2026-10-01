<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use App\Models\CareerReport;
use App\Models\CvDocument;
use App\Models\CvVersion;
use App\Models\InterviewSession;
use App\Models\JobWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InsightsController
{
    public function dashboard(Request $r)
    {
        $uid = $r->user()->id;
        $statuses = Application::where('user_id', $uid)->select('status', DB::raw('count(*) total'))->groupBy('status')->pluck('total', 'status');

        return ['applications' => Application::where('user_id', $uid)->count(), 'cv_versions' => CvVersion::where('user_id', $uid)->count(), 'cv_documents' => CvDocument::where('user_id', $uid)->count(), 'cover_letters' => CareerReport::where('user_id', $uid)->where('type', 'cover_letter')->count(), 'interviews' => InterviewSession::where('user_id', $uid)->count(), 'workspaces' => JobWorkspace::where('user_id', $uid)->count(), 'statuses' => $statuses, 'reminders' => ApplicationResource::collection(Application::where('user_id', $uid)->whereNotNull('reminder_at')->where('reminder_at', '>=', now())->orderBy('reminder_at')->limit(5)->get())];
    }

    public function analytics(Request $r)
    {
        $q = Application::where('user_id', $r->user()->id);
        $total = (clone $q)->count();
        $counts = (clone $q)->select('status', DB::raw('count(*) total'))->groupBy('status')->pluck('total', 'status');
        $interviews = (int) ($counts['interview'] ?? 0);
        $offers = (int) ($counts['offer'] ?? 0);

        return ['total' => $total, 'by_status' => $counts, 'interview_rate' => $total ? round(($interviews + $offers) / $total * 100, 1) : 0, 'offer_rate' => $total ? round($offers / $total * 100, 1) : 0, 'with_cv_version' => (clone $q)->whereNotNull('cv_version_id')->count(), 'without_cv_version' => (clone $q)->whereNull('cv_version_id')->count(), 'recent' => ApplicationResource::collection((clone $q)->latest()->limit(20)->get())];
    }
}
