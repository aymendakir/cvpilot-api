<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Career\DiagnosticRequest;
use App\Http\Requests\Career\DocumentsRequest;
use App\Http\Requests\Career\DocumentsWithIdentityRequest;
use App\Http\Requests\Career\FollowUpRequest;
use App\Http\Requests\Career\PortfolioReviewRequest;
use App\Models\Application;
use App\Services\AiGateway;
use App\Services\Prompts\AssistantPrompts;
use Illuminate\Support\Facades\Http;

class CareerAiController
{
    use Concerns\GeneratesReports;

    public function recruiterView(DocumentsRequest $r, AiGateway $ai, AssistantPrompts $prompts)
    {
        $d = $r->validated();

        return $this->report($r, $ai, 'recruiter_view', $prompts->recruiterView($d), $d);
    }

    public function tailorCv(DocumentsWithIdentityRequest $r, AiGateway $ai, AssistantPrompts $prompts)
    {
        $d = $r->validated();
        $prompt = $prompts->tailorCv($d);

        return $this->report($r, $ai, 'tailor_cv', $prompt, $d);
    }

    public function applicationPack(DocumentsWithIdentityRequest $r, AiGateway $ai, AssistantPrompts $prompts)
    {
        $d = $r->validated();
        $prompt = $prompts->applicationPack($d);

        return $this->report($r, $ai, 'application_pack', $prompt, $d);
    }

    public function skillGap(DocumentsRequest $r, AiGateway $ai, AssistantPrompts $prompts)
    {
        $d = $r->validated();
        $prompt = $prompts->skillGap($d);

        return $this->report($r, $ai, 'skill_gap', $prompt, $d);
    }

    public function portfolio(PortfolioReviewRequest $r, AiGateway $ai, AssistantPrompts $prompts)
    {
        $d = $r->validated();
        $repos = [];
        $github = $d['github_url'] ?? '';
        $parts = $github ? parse_url($github) : [];
        if (($parts['host'] ?? '') === 'github.com' && ! empty($parts['path'])) {
            $username = explode('/', trim($parts['path'], '/'))[0] ?? '';
            if (preg_match('/^[A-Za-z0-9-]{1,39}$/', $username)) {
                try {
                    $response = Http::timeout(12)->withHeaders(['User-Agent' => 'CVPilot-AI'])->get("https://api.github.com/users/{$username}/repos", ['per_page' => 30, 'sort' => 'updated']);
                    if ($response->successful()) {
                        $repos = collect($response->json())->map(fn ($repo) => ['name' => $repo['name'] ?? '', 'description' => $repo['description'] ?? null, 'language' => $repo['language'] ?? null, 'topics' => $repo['topics'] ?? [], 'stars' => $repo['stargazers_count'] ?? 0, 'fork' => $repo['fork'] ?? false])->all();
                    }
                } catch (\Throwable) {
                }
            }
        }
        $prompt = $prompts->portfolio($d, $repos);
        $d['github_repositories'] = $repos;

        return $this->report($r, $ai, 'portfolio_analysis', $prompt, $d);
    }

    public function followUp(FollowUpRequest $r, AiGateway $ai, AssistantPrompts $prompts)
    {
        $d = $r->validated();
        $prompt = $prompts->followUp($d);

        return $this->report($r, $ai, 'follow_up', $prompt, $d);
    }

    public function diagnostic(DiagnosticRequest $r, AiGateway $ai, AssistantPrompts $prompts)
    {
        $apps = Application::where('user_id', $r->user()->id)->latest()->limit(100)->get(['title', 'company', 'status', 'match_score', 'cv_version_id', 'application_date', 'notes']);
        abort_if($apps->count() < 3, 422, 'Add at least 3 applications before running this diagnosis.');
        $prompt = $prompts->diagnostic($apps->toJson(), $apps->toArray(), $r->validated('language'));

        return $this->report($r, $ai, 'career_diagnostic', $prompt, ['applications' => $apps->toArray()]);
    }
}
