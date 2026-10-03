<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Career\DiagnosticRequest;
use App\Http\Requests\Career\DocumentsRequest;
use App\Http\Requests\Career\DocumentsWithIdentityRequest;
use App\Http\Requests\Career\FollowUpRequest;
use App\Http\Requests\Career\PortfolioReviewRequest;
use App\Models\Application;
use App\Services\AiGateway;
use App\Services\OutputLanguage;
use Illuminate\Support\Facades\Http;

class CareerAiController
{
    use Concerns\GeneratesReports;

    public function recruiterView(DocumentsRequest $r, AiGateway $ai)
    {
        $d = $r->validated();

        return $this->report($r, $ai, 'recruiter_view', "Review this CV as a recruiter spending only 10-20 seconds. Never invent facts. Return: FIRST IMPRESSION, WHAT STANDS OUT, HARD-TO-FIND INFORMATION, RED FLAGS OR CONFUSION, and 5 FAST FIXES.\n\nCV:\n{$d['cv_text']}\n\nTARGET JOB:\n{$d['job_description']}".OutputLanguage::instruction($d['language'] ?? null), $d);
    }

    public function tailorCv(DocumentsWithIdentityRequest $r, AiGateway $ai)
    {
        $d = $r->validated();
        $prompt = "Rewrite this CV for {$d['title']} at ".(($d['company'] ?? '') ?: 'the company').". Preserve all facts and never invent experience, skills, dates, metrics, employers or education. Improve ordering, summary, bullets and truthful job keywords. Keep an ATS-friendly plain-text structure. Return only the complete tailored CV, ready to edit, with no commentary.\n\nORIGINAL CV:\n{$d['cv_text']}\n\nJOB:\n{$d['job_description']}".OutputLanguage::instruction($d['language'] ?? null);

        return $this->report($r, $ai, 'tailor_cv', $prompt, $d);
    }

    public function applicationPack(DocumentsWithIdentityRequest $r, AiGateway $ai)
    {
        $d = $r->validated();
        $prompt = "Create a complete truthful application pack for {$d['title']} at ".(($d['company'] ?? '') ?: 'the company').". Never invent experience, skills, metrics, employers or education. Use only the CV and job text. Return exactly these sections: TAILORED CV, COVER LETTER, RECRUITER MESSAGE, INTERVIEW QUESTIONS, KEY JOB NOTES. Keep every section practical and ready to edit.\n\nCV:\n{$d['cv_text']}\n\nJOB:\n{$d['job_description']}".OutputLanguage::instruction($d['language'] ?? null);

        return $this->report($r, $ai, 'application_pack', $prompt, $d);
    }

    public function skillGap(DocumentsRequest $r, AiGateway $ai)
    {
        $d = $r->validated();
        $prompt = "Build a realistic skill-gap roadmap from this CV and job. Separate ALREADY HAVE, MISSING OR WEAK, PRIORITY ORDER, WHY EACH SKILL MATTERS, 30-DAY PLAN, and MINI-PROJECT IDEAS. Do not claim a skill is missing if the CV proves it. Do not promise hiring outcomes.\n\nCV:\n{$d['cv_text']}\n\nJOB:\n{$d['job_description']}".OutputLanguage::instruction($d['language'] ?? null);

        return $this->report($r, $ai, 'skill_gap', $prompt, $d);
    }

    public function portfolio(PortfolioReviewRequest $r, AiGateway $ai)
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
        }$prompt = "Analyze the supplied professional portfolio or work examples against the job. Public GitHub repository metadata is included when available; do not claim you inspected source code. Portfolio links are not fetched, so use only supplied project details. Return: BEST PROJECTS TO FEATURE, EVIDENCE MISSING, GITHUB/PORTFOLIO IMPROVEMENTS, and PROJECT IDEAS. Never invent repository content.\nGitHub: ".($github ?: 'not supplied')."\nPublic repository metadata:\n".json_encode($repos)."\nPortfolio: ".($d['portfolio_url'] ?? 'not supplied')."\nProject details:\n".($d['projects'] ?? 'none')."\n\nCV:\n{$d['cv_text']}\n\nJOB:\n{$d['job_description']}".OutputLanguage::instruction($d['language'] ?? null);
        $d['github_repositories'] = $repos;

        return $this->report($r, $ai, 'portfolio_analysis', $prompt, $d);
    }

    public function followUp(FollowUpRequest $r, AiGateway $ai)
    {
        $d = $r->validated();
        $prompt = "Write a concise {$d['type']} message with a ".($d['tone'] ?? 'professional')." tone for {$d['title']} at {$d['company']}. Use only supplied context, no invented claims. Return a subject line when appropriate, then the message only. Sender: ".($d['name'] ?? '[Your name]')."\nContext:\n".($d['context'] ?? '').OutputLanguage::instruction($d['language'] ?? null);

        return $this->report($r, $ai, 'follow_up', $prompt, $d);
    }

    public function diagnostic(DiagnosticRequest $r, AiGateway $ai)
    {
        $apps = Application::where('user_id', $r->user()->id)->latest()->limit(100)->get(['title', 'company', 'status', 'match_score', 'cv_version_id', 'application_date', 'notes']);
        abort_if($apps->count() < 3, 422, 'Add at least 3 applications before running this diagnosis.');
        $prompt = "Analyze these application outcomes and identify patterns that may explain low interview response. Treat every conclusion as a diagnostic indicator, not a fact. Use headings: OBSERVED PATTERNS, POSSIBLE CAUSES, WHAT THE DATA CANNOT PROVE, NEXT 3 EXPERIMENTS, and WHAT TO TRACK NEXT. Do not blame protected traits and do not promise outcomes.\n\nAPPLICATION DATA:\n".$apps->toJson().OutputLanguage::instruction($r->validated('language'));

        return $this->report($r, $ai, 'career_diagnostic', $prompt, ['applications' => $apps->toArray()]);
    }
}
