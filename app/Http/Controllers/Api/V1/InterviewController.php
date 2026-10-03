<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Career\DocumentsWithIdentityRequest;
use App\Http\Requests\Career\ReplyInterviewRequest;
use App\Http\Resources\InterviewSessionResource;
use App\Models\InterviewSession;
use App\Services\AdminReview;
use App\Services\AiGateway;
use App\Services\OutputLanguage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class InterviewController
{
    use Concerns\GeneratesReports;

    public function store(DocumentsWithIdentityRequest $r, AiGateway $ai)
    {
        $d = $r->validated();
        $prompt = "Act as a structured interviewer for {$d['title']} at ".(($d['company'] ?? '') ?: 'the company').". Read the CV and job. Ask exactly one relevant interview question. Do not score. Return only the question.\nCV:\n{$d['cv_text']}\nJOB:\n{$d['job_description']}".OutputLanguage::instruction($d['language'] ?? null);
        $result = $ai->chat($prompt, 'interview', $r->user()->id);
        $session = InterviewSession::create(['user_id' => $r->user()->id, 'title' => $d['title'], 'company' => $d['company'] ?? null, 'cv_text' => $d['cv_text'], 'job_description' => $d['job_description'], 'language' => $d['language'] ?? null, 'transcript' => [['role' => 'assistant', 'content' => $result['answer']]]]);
        AdminReview::record('interview', $session);
        AuthController::audit($r, 'interview_started', $r->user()->id);

        return ['session_id' => $session->id, 'question' => $result['answer']];
    }

    public function show(Request $r, InterviewSession $session)
    {
        Gate::forUser($r->user())->authorize('view', $session);

        return InterviewSessionResource::make($session);
    }

    public function destroy(Request $r, InterviewSession $session)
    {
        Gate::forUser($r->user())->authorize('delete', $session);
        AdminReview::forget('interview', $session->id);
        $session->delete();

        return response()->noContent();
    }

    public function reply(ReplyInterviewRequest $r, InterviewSession $session, AiGateway $ai)
    {
        abort_unless($session->status === 'active', 422, 'This interview is already completed.');
        $d = $r->validated();
        $transcript = $session->transcript;
        $transcript[] = ['role' => 'user', 'content' => $d['answer']];
        $prompt = "Continue this mock interview. Give brief useful feedback on the candidate's last answer without a numeric score, then ask exactly one new question. Return with headings FEEDBACK and NEXT QUESTION. Never invent facts.\nCV:\n{$session->cv_text}\nJOB:\n{$session->job_description}\nTRANSCRIPT:\n".json_encode($transcript).OutputLanguage::instruction($session->language);
        $result = $ai->chat($prompt, 'interview', $r->user()->id);
        $transcript[] = ['role' => 'assistant', 'content' => $result['answer']];
        $session->update(['transcript' => $transcript]);
        AdminReview::record('interview', $session);

        return ['reply' => $result['answer']];
    }

    public function finish(Request $r, InterviewSession $session, AiGateway $ai)
    {
        Gate::forUser($r->user())->authorize('update', $session);
        if ($session->status === 'completed') {
            return ['feedback' => $session->feedback];
        }$prompt = "Give final mock-interview feedback without a numeric score. Use headings: STRONG ANSWERS, ANSWERS TO IMPROVE, MISSING EVIDENCE, COMMUNICATION FEEDBACK, and NEXT PRACTICE STEPS. Base everything only on this transcript and CV.\nCV:\n{$session->cv_text}\nJOB:\n{$session->job_description}\nTRANSCRIPT:\n".json_encode($session->transcript).OutputLanguage::instruction($session->language);
        $result = $ai->chat($prompt, 'interview', $r->user()->id);
        $session->update(['feedback' => $result['answer'], 'status' => 'completed']);
        AdminReview::record('interview', $session);
        AuthController::audit($r, 'interview_completed', $r->user()->id);

        return ['feedback' => $result['answer']];
    }
}
