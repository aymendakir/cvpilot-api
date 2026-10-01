<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\AuthController;
use App\Models\AdminReviewItem;
use App\Models\Application;
use App\Models\CareerReport;
use App\Models\CvDocument;
use App\Models\CvVersion;
use App\Models\InterviewSession;
use App\Models\User;
use App\Services\PlatformMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AdminController
{
    public function summary(Request $r)
    {
        AuthController::audit($r, 'dashboard_view', $r->user()->id);
        $daily = DB::table('audit_events')->where('created_at', '>=', now()->startOfDay()->subDays(6))->selectRaw('DATE(created_at) as day, COUNT(*) as events')->groupByRaw('DATE(created_at)')->pluck('events', 'day');
        $activity = collect(range(6, 0))->map(fn ($days) => ['day' => now()->subDays($days)->toDateString(), 'events' => (int) ($daily[now()->subDays($days)->toDateString()] ?? 0)])->all();

        return ['activity' => $activity, 'users' => User::count(), 'verified_users' => User::whereNotNull('verified_at')->count(), 'new_users_7d' => User::where('created_at', '>=', now()->subDays(7))->count(), 'active_users_24h' => DB::table('audit_events')->where('created_at', '>=', now()->subDay())->whereNotNull('user_id')->distinct()->count('user_id'), 'failed_logins_24h' => DB::table('audit_events')->where('event', 'login_failed')->where('created_at', '>=', now()->subDay())->count(), 'cv_documents' => DB::table('cv_documents')->count(), 'ats_reports' => DB::table('ats_reports')->count(), 'applications' => DB::table('applications')->count(), 'ai_requests_7d' => DB::table('ai_usage')->where('created_at', '>=', now()->subDays(7))->count(), 'ai_failures_24h' => DB::table('ai_usage')->where('success', false)->where('created_at', '>=', now()->subDay())->count(), 'job_searches_7d' => DB::table('job_search_events')->where('created_at', '>=', now()->subDays(7))->count(), 'job_search_failures_24h' => DB::table('job_search_events')->where('success', false)->where('created_at', '>=', now()->subDay())->count(), 'integrations_enabled' => DB::table('integrations')->where('enabled', true)->count(), 'note' => 'Counts come from the application database. IP metadata is recorded only for security auditing.'];
    }

    public function users(Request $r)
    {
        $r->validate(['search' => 'nullable|string|max:120', 'page' => 'nullable|integer|min:1']);

        return User::when($r->search, fn ($q) => $q->where(fn ($s) => $s->where('name', 'like', '%'.$r->search.'%')->orWhere('email', 'like', '%'.$r->search.'%')))->orderByDesc('id')->paginate(20);
    }

    public function suspend(Request $r, User $user)
    {
        abort_if($user->role === 'admin', 422, 'Admin accounts cannot be suspended here.');
        $d = $r->validate(['suspended' => 'required|boolean']);
        $user->suspended = $d['suspended'];
        $user->session_version++;
        $user->save();
        AuthController::audit($r, 'user_'.$user->id.($user->suspended ? '_suspended' : '_enabled'), $r->user()->id);

        return $user;
    }

    public function logs(Request $r)
    {
        $r->validate(['event' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1']);

        return DB::table('audit_events')->leftJoin('users', 'users.id', '=', 'audit_events.user_id')->select('audit_events.*', 'users.name as user_name', 'users.email as user_email')->when($r->event, fn ($q) => $q->where('event', $r->event))->orderByDesc('audit_events.id')->paginate(30);
    }

    public function detail(Request $r, User $user)
    {
        $counts = [];
        foreach (['cv_documents', 'cv_versions', 'interview_sessions', 'job_workspaces', 'applications', 'career_reports'] as $table) {
            $counts[$table] = DB::table($table)->where('user_id', $user->id)->count();
        }AuthController::audit($r, 'user_reviewed:'.$user->id, $r->user()->id);

        return ['user' => $user, 'counts' => $counts, 'activity' => DB::table('audit_events')->where('user_id', $user->id)->latest('id')->limit(100)->get(['id', 'event', 'ip', 'user_agent', 'created_at']), 'uploads' => CvDocument::where('user_id', $user->id)->latest()->get()->makeVisible(['extracted_text']), 'cv_versions' => CvVersion::where('user_id', $user->id)->latest()->get(), 'applications' => Application::where('user_id', $user->id)->latest()->get(), 'interviews' => InterviewSession::where('user_id', $user->id)->latest()->get(), 'reports' => CareerReport::where('user_id', $user->id)->latest()->get(), 'reviews' => AdminReviewItem::where('user_id', $user->id)->latest('updated_at')->limit(50)->get()];
    }

    public function applications(Request $r)
    {
        $r->validate(['page' => 'nullable|integer|min:1', 'search' => 'nullable|string|max:120', 'status' => 'nullable|string|max:40']);
        $validIds = AdminReviewItem::where('kind', 'application')->where('expires_at', '>', now())->pluck('record_id');

        return Application::whereIn('id', $validIds)->with('user:id,name,email')->when($r->search, fn ($q) => $q->where(fn ($s) => $s->where('title', 'like', '%'.$r->search.'%')->orWhere('company', 'like', '%'.$r->search.'%')->orWhereHas('user', fn ($u) => $u->where('name', 'like', '%'.$r->search.'%')->orWhere('email', 'like', '%'.$r->search.'%'))))->when($r->status, fn ($q) => $q->where('status', $r->status))->latest()->paginate(20);
    }

    public function warning(Request $r, User $user, PlatformMail $mail)
    {
        $d = $r->validate(['subject' => 'required|string|max:180', 'message' => 'required|string|min:5|max:5000', 'severity' => 'nullable|string|in:notice,warning,urgent']);
        $emailSent = false;
        $errorMsg = null;
        try {
            $mail->send($user->email, $d['subject'], 'emails.notice', ['name' => $user->name, 'heading' => $d['subject'], 'noticeMessage' => $d['message']]);
            $emailSent = true;
        } catch (\Throwable $e) {
            $errorMsg = $e->getMessage();
        }$payload = json_encode(['subject' => $d['subject'], 'message' => $d['message'], 'severity' => $d['severity'] ?? 'warning', 'email_sent' => $emailSent, 'admin_id' => $r->user()->id]);
        DB::table('audit_events')->insert(['user_id' => $user->id, 'event' => 'warning: '.substr($d['subject'], 0, 120), 'ip' => $r->ip(), 'user_agent' => substr($payload, 0, 512), 'created_at' => now()]);
        AuthController::audit($r, 'warning_sent:'.$user->id, $r->user()->id);

        return ['message' => $emailSent ? 'Warning email sent successfully and recorded in user logs.' : 'Warning recorded in user logs and history (Email not delivered: configure SMTP in dashboard).', 'email_sent' => $emailSent];
    }

    /** v1: 201 Created. The legacy route keeps the 200 from warning(). Removed with the legacy aliases. */
    public function storeWarning(Request $r, User $user, PlatformMail $mail)
    {
        return response()->json($this->warning($r, $user, $mail), 201);
    }

    public function download(Request $r, CvDocument $cv)
    {
        abort_if($cv->expires_at && $cv->expires_at->isPast(), 404, 'File not found or expired.');
        abort_unless(Storage::disk('local')->exists($cv->disk_path), 404, 'File not found on storage.');
        AuthController::audit($r, 'upload_reviewed:'.$cv->id, $r->user()->id);

        return Storage::disk('local')->download($cv->disk_path, $cv->name, ['Cache-Control' => 'private, no-store']);
    }

    public function integrations()
    {
        return DB::table('integrations')->select('provider', 'model', 'enabled', 'updated_at')->get();
    }

    public function integration(Request $r)
    {
        $d = $r->validate(['provider' => 'required|in:openai', 'secret' => 'required|string|max:500', 'model' => 'required|string|max:100', 'enabled' => 'required|boolean']);
        DB::table('integrations')->updateOrInsert(['provider' => $d['provider']], ['secret' => Crypt::encryptString($d['secret']), 'model' => $d['model'], 'enabled' => $d['enabled'], 'updated_at' => now(), 'created_at' => now()]);
        AuthController::audit($r, 'integration_updated', $r->user()->id);

        return ['message' => 'Encrypted credentials saved. Connection has not been tested.'];
    }
}
