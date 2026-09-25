<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB, Hash, Storage};

class AccountDataController {
    public function export(Request $request) {
        $id = $request->user()->id;
        $data = ['exported_at'=>now()->toIso8601String(), 'profile'=>$request->user()->toArray()];
        foreach (['cv_versions', 'job_workspaces', 'interview_sessions', 'career_reports', 'applications', 'ats_reports', 'job_searches'] as $table) {
            $data[$table] = DB::table($table)->where('user_id', $id)->get();
        }
        $data['uploads'] = DB::table('cv_documents')->where('user_id', $id)
            ->get(['id', 'name', 'mime', 'size', 'extracted_text', 'created_at', 'expires_at']);
        AuthController::audit($request, 'account_exported', $id);
        return response()->json($data)->header('Content-Disposition', 'attachment; filename="cvpilot-account.json"');
    }

    public function destroy(Request $request) {
        $user = $request->user();
        abort_if($user->role === 'admin', 422, 'Administrator accounts cannot be deleted through personal settings.');
        $data = $request->validate(['current_password'=>'required|string', 'confirmation'=>'required|in:DELETE']);
        abort_unless(Hash::check($data['current_password'], $user->password), 422, 'Current password is incorrect.');
        $files = DB::table('cv_documents')->where('user_id', $user->id)->pluck('disk_path');
        foreach ($files as $path) {
            if (Storage::disk('local')->exists($path) && !Storage::disk('local')->delete($path)) {
                abort(503, 'An uploaded file could not be removed. Please retry.');
            }
        }
        DB::transaction(function () use ($user) {
            // Remove personal audit information; related career records use cascading foreign keys.
            DB::table('audit_events')->where('user_id', $user->id)->delete();
            DB::table('job_search_events')->where('user_id', $user->id)->delete();
            DB::table('ai_usage')->where('user_id', $user->id)->delete();
            DB::table('support_messages')->where('email', $user->email)->delete();
            $user->delete();
        });
        Cache::forget('otp:verify:'.$user->id); Cache::forget('otp:reset:'.$user->id);
        $request->session()->invalidate(); $request->session()->regenerateToken();
        return ['message'=>'Your account and saved career data have been deleted.'];
    }
}
