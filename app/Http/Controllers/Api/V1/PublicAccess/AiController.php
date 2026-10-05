<?php

namespace App\Http\Controllers\Api\V1\PublicAccess;

use App\Http\Requests\Ai\CoverLetterRequest;
use App\Http\Requests\Career\DocumentsRequest;
use App\Http\Requests\Career\FollowUpRequest;
use App\Services\AiGateway;
use App\Services\Prompts\AssistantPrompts;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/public/ai/* (API-D, `cv-ai/SPEC.md` §18.3): four career tools without an account, for the
 * inline tools on the landing pages. Same input rules as the signed-in routes; the prompt always uses
 * the S6 envelope, because the text comes from anyone. Nothing is stored or logged: no report, no admin
 * copy, no audit line. The answer is the text alone.
 */
class AiController
{
    private readonly AssistantPrompts $prompts;

    public function __construct(private readonly AiGateway $ai)
    {
        $this->prompts = new AssistantPrompts(true);
    }

    public function coverLetter(CoverLetterRequest $r): JsonResponse
    {
        return $this->answer($this->prompts->coverLetter($r->validated()), 'public_cover_letter');
    }

    public function recruiterView(DocumentsRequest $r): JsonResponse
    {
        return $this->answer($this->prompts->recruiterView($r->validated()), 'public_recruiter_view');
    }

    public function skillGap(DocumentsRequest $r): JsonResponse
    {
        return $this->answer($this->prompts->skillGap($r->validated()), 'public_skill_gap');
    }

    public function followUp(FollowUpRequest $r): JsonResponse
    {
        return $this->answer($this->prompts->followUp($r->validated()), 'public_follow_up');
    }

    private function answer(string $prompt, string $feature): JsonResponse
    {
        // ai_usage keeps provider, model, feature and latency with no user: no text, no address.
        $result = $this->ai->chat($prompt, $feature);

        return response()->json(['answer' => $result['answer']]);
    }
}
