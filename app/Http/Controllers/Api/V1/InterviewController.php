<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Career\DocumentsWithIdentityRequest;
use App\Http\Requests\Career\ReplyInterviewRequest;
use App\Http\Resources\InterviewSessionResource;
use App\Models\InterviewSession;
use App\Services\AdminReview;
use App\Services\AiGateway;
use App\Services\Prompts\AssistantPrompts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class InterviewController
{
    use Concerns\GeneratesReports;

    public function store(DocumentsWithIdentityRequest $r, AiGateway $ai, AssistantPrompts $prompts)
    {
        $d = $r->validated();
        $prompt = $prompts->interviewStart($d);
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

    public function reply(ReplyInterviewRequest $r, InterviewSession $session, AiGateway $ai, AssistantPrompts $prompts)
    {
        abort_unless($session->status === 'active', 422, 'This interview is already completed.');
        $d = $r->validated();
        $transcript = $session->transcript;
        $transcript[] = ['role' => 'user', 'content' => $d['answer']];
        $prompt = $prompts->interviewReply($session->cv_text, $session->job_description, $transcript, $session->language);
        $result = $ai->chat($prompt, 'interview', $r->user()->id);
        $transcript[] = ['role' => 'assistant', 'content' => $result['answer']];
        $session->update(['transcript' => $transcript]);
        AdminReview::record('interview', $session);

        return ['reply' => $result['answer']];
    }

    public function finish(Request $r, InterviewSession $session, AiGateway $ai, AssistantPrompts $prompts)
    {
        Gate::forUser($r->user())->authorize('update', $session);
        if ($session->status === 'completed') {
            return ['feedback' => $session->feedback];
        }
        $prompt = $prompts->interviewFinish($session->cv_text, $session->job_description, $session->transcript, $session->language);
        $result = $ai->chat($prompt, 'interview', $r->user()->id);
        $session->update(['feedback' => $result['answer'], 'status' => 'completed']);
        AdminReview::record('interview', $session);
        AuthController::audit($r, 'interview_completed', $r->user()->id);

        return ['feedback' => $result['answer']];
    }
}
