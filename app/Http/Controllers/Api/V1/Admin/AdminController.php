<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Requests\Admin\CreateUserRequest;
use App\Http\Requests\Admin\ListApplicationsRequest;
use App\Http\Requests\Admin\ListAuditEventsRequest;
use App\Http\Requests\Admin\ListUsersRequest;
use App\Http\Requests\Admin\SendWarningRequest;
use App\Http\Requests\Admin\SuspendUserRequest;
use App\Http\Resources\AdminApplicationResource;
use App\Http\Resources\AdminCareerReportResource;
use App\Http\Resources\AdminCvDocumentResource;
use App\Http\Resources\AdminCvVersionResource;
use App\Http\Resources\AdminInterviewSessionResource;
use App\Http\Resources\AdminUserResource;
use App\Http\Resources\ApplicationResource;
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
use Illuminate\Support\Facades\Hash;

class AdminController
{
    public function summary(Request $r)
    {
        AuthController::audit($r, 'dashboard_view', $r->user()->id);
        $daily = DB::table('audit_events')->where('created_at', '>=', now()->startOfDay()->subDays(6))->selectRaw('DATE(created_at) as day, COUNT(*) as events')->groupByRaw('DATE(created_at)')->pluck('events', 'day');
        $activity = collect(range(6, 0))->map(fn ($days) => ['day' => now()->subDays($days)->toDateString(), 'events' => (int) ($daily[now()->subDays($days)->toDateString()] ?? 0)])->all();

        return ['activity' => $activity, 'users' => User::count(), 'verified_users' => User::whereNotNull('verified_at')->count(), 'new_users_7d' => User::where('created_at', '>=', now()->subDays(7))->count(), 'active_users_24h' => DB::table('audit_events')->where('created_at', '>=', now()->subDay())->whereNotNull('user_id')->distinct()->count('user_id'), 'failed_logins_24h' => DB::table('audit_events')->where('event', 'login_failed')->where('created_at', '>=', now()->subDay())->count(), 'cv_documents' => DB::table('cv_documents')->count(), 'ats_reports' => DB::table('ats_reports')->count(), 'applications' => DB::table('applications')->count(), 'ai_requests_7d' => DB::table('ai_usage')->where('created_at', '>=', now()->subDays(7))->count(), 'ai_failures_24h' => DB::table('ai_usage')->where('success', false)->where('created_at', '>=', now()->subDay())->count(), 'job_searches_7d' => DB::table('job_search_events')->where('created_at', '>=', now()->subDays(7))->count(), 'job_search_failures_24h' => DB::table('job_search_events')->where('success', false)->where('created_at', '>=', now()->subDay())->count(), 'integrations_enabled' => DB::table('integrations')->where('enabled', true)->count(), 'note' => 'Counts come from the application database. IP metadata is recorded only for security auditing.'];
    }

    public function users(ListUsersRequest $r)
    {
        return AdminUserResource::paginate(User::when($r->search, fn ($q) => $q->where(fn ($s) => $s->where('name', 'like', '%'.$r->search.'%')->orWhere('email', 'like', '%'.$r->search.'%')))->orderByDesc('id')->paginate($r->perPage(20)));
    }

    /**
     * Creates an account on behalf of an admin. No email is sent; an unverified account must
     * verify its email before it can sign in. The password is only ever stored hashed.
     */
    public function storeUser(CreateUserRequest $r)
    {
        $d = $r->validated();

        $user = new User;
        $user->name = $d['name'];
        $user->email = $d['email'];
        $user->password = Hash::make($d['password']);
        $user->role = $d['role'];
        $user->verified_at = ! empty($d['verified']) ? now() : null;
        $user->save();

        AuthController::audit($r, 'user_created:'.$user->id, $r->user()->id);
        if ($user->role === 'admin') {
            AuthController::audit($r, 'admin_account_created:'.$user->id, $r->user()->id);
        }

        return AdminUserResource::make($user->refresh())->response()->setStatusCode(201);
    }

    public function suspend(SuspendUserRequest $r, User $user)
    {
        abort_if($user->role === 'admin', 422, 'Admin accounts cannot be suspended here.');
        $d = $r->validated();
        $user->suspended = $d['suspended'];
        $user->session_version++;
        $user->save();
        AuthController::audit($r, 'user_'.$user->id.($user->suspended ? '_suspended' : '_enabled'), $r->user()->id);

        return AdminUserResource::make($user);
    }

    public function logs(ListAuditEventsRequest $r)
    {

        return DB::table('audit_events')->leftJoin('users', 'users.id', '=', 'audit_events.user_id')->select('audit_events.*', 'users.name as user_name', 'users.email as user_email')->when($r->event, fn ($q) => $q->where('event', $r->event))->orderByDesc('audit_events.id')->paginate($r->perPage(30));
    }

    /**
     * Account overview for support: metadata and activity only. CV text (uploaded text, saved CV
     * versions, the CV inside interviews and reports, the temporary admin copies) is not returned.
     */
    public function detail(Request $r, User $user)
    {
        $counts = [];
        foreach (['cv_documents', 'cv_versions', 'interview_sessions', 'job_workspaces', 'applications', 'career_reports'] as $table) {
            $counts[$table] = DB::table($table)->where('user_id', $user->id)->count();
        }
        AuthController::audit($r, 'user_reviewed:'.$user->id, $r->user()->id);

        return [
            'user' => AdminUserResource::make($user),
            'counts' => $counts,
            'activity' => DB::table('audit_events')->where('user_id', $user->id)->latest('id')->limit(100)->get(['id', 'event', 'ip', 'user_agent', 'created_at']),
            'uploads' => AdminCvDocumentResource::collection(CvDocument::where('user_id', $user->id)->latest()->get()),
            'cv_versions' => AdminCvVersionResource::collection(CvVersion::where('user_id', $user->id)->latest()->get()),
            'applications' => ApplicationResource::collection(Application::where('user_id', $user->id)->latest()->get()),
            'interviews' => AdminInterviewSessionResource::collection(InterviewSession::where('user_id', $user->id)->latest()->get()),
            'reports' => AdminCareerReportResource::collection(CareerReport::where('user_id', $user->id)->latest()->get()),
        ];
    }

    public function applications(ListApplicationsRequest $r)
    {
        $validIds = AdminReviewItem::where('kind', 'application')->where('expires_at', '>', now())->pluck('record_id');

        return AdminApplicationResource::paginate(Application::whereIn('id', $validIds)->with('user:id,name,email')->when($r->search, fn ($q) => $q->where(fn ($s) => $s->where('title', 'like', '%'.$r->search.'%')->orWhere('company', 'like', '%'.$r->search.'%')->orWhereHas('user', fn ($u) => $u->where('name', 'like', '%'.$r->search.'%')->orWhere('email', 'like', '%'.$r->search.'%'))))->when($r->status, fn ($q) => $q->where('status', $r->status))->latest()->paginate($r->perPage(20)));
    }

    /** Records the warning, mails the user when SMTP works, and answers 201 with the outcome. */
    public function storeWarning(SendWarningRequest $r, User $user, PlatformMail $mail)
    {
        $d = $r->validated();
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

        return response()->json(['message' => $emailSent ? 'Warning email sent successfully and recorded in user logs.' : 'Warning recorded in user logs and history (Email not delivered: configure SMTP in dashboard).', 'email_sent' => $emailSent], 201);
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
