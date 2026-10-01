<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\InterviewSession;
use App\Services\AdminReview;
use App\Services\AiGateway;
use Illuminate\Http\Request;

class InterviewController
{
    use Concerns\GeneratesReports;

    public function store(Request $r, AiGateway $ai)
    {
        $d = $this->documents($r, true);
        $prompt = "Act as a structured interviewer for {$d['title']} at ".(($d['company'] ?? '') ?: 'the company').". Read the CV and job. Ask exactly one relevant interview question. Do not score. Return only the question.\nCV:\n{$d['cv_text']}\nJOB:\n{$d['job_description']}";
        $result = $ai->chat($prompt, 'interview', $r->user()->id);
        $session = InterviewSession::create(['user_id' => $r->user()->id, 'title' => $d['title'], 'company' => $d['company'] ?? null, 'cv_text' => $d['cv_text'], 'job_description' => $d['job_description'], 'transcript' => [['role' => 'assistant', 'content' => $result['answer']]]]);
        AdminReview::record('interview', $session);
        AuthController::audit($r, 'interview_started', $r->user()->id);

        return ['session_id' => $session->id, 'question' => $result['answer']];
    }

    public function show(Request $r, InterviewSession $session)
    {
        abort_unless($session->user_id === $r->user()->id, 404);

        return $session;
    }

    public function destroy(Request $r, InterviewSession $session)
    {
        abort_unless($session->user_id === $r->user()->id, 404);
        AdminReview::forget('interview', $session->id);
        $session->delete();

        return response()->noContent();
    }

    public function reply(Request $r, InterviewSession $session, AiGateway $ai)
    {
        abort_unless($session->user_id === $r->user()->id, 404);
        abort_unless($session->status === 'active', 422, 'This interview is already completed.');
        $d = $r->validate(['answer' => 'required|string|min:2|max:6000']);
        $transcript = $session->transcript;
        $transcript[] = ['role' => 'user', 'content' => $d['answer']];
        $prompt = "Continue this mock interview. Give brief useful feedback on the candidate's last answer without a numeric score, then ask exactly one new question. Return with headings FEEDBACK and NEXT QUESTION. Never invent facts.\nCV:\n{$session->cv_text}\nJOB:\n{$session->job_description}\nTRANSCRIPT:\n".json_encode($transcript);
        $result = $ai->chat($prompt, 'interview', $r->user()->id);
        $transcript[] = ['role' => 'assistant', 'content' => $result['answer']];
        $session->update(['transcript' => $transcript]);
        AdminReview::record('interview', $session);

        return ['reply' => $result['answer']];
    }

    public function finish(Request $r, InterviewSession $session, AiGateway $ai)
    {
        abort_unless($session->user_id === $r->user()->id, 404);
        if ($session->status === 'completed') {
            return ['feedback' => $session->feedback];
        }$prompt = "Give final mock-interview feedback without a numeric score. Use headings: STRONG ANSWERS, ANSWERS TO IMPROVE, MISSING EVIDENCE, COMMUNICATION FEEDBACK, and NEXT PRACTICE STEPS. Base everything only on this transcript and CV.\nCV:\n{$session->cv_text}\nJOB:\n{$session->job_description}\nTRANSCRIPT:\n".json_encode($session->transcript);
        $result = $ai->chat($prompt, 'interview', $r->user()->id);
        $session->update(['feedback' => $result['answer'], 'status' => 'completed']);
        AdminReview::record('interview', $session);
        AuthController::audit($r, 'interview_completed', $r->user()->id);

        return ['feedback' => $result['answer']];
    }
}
