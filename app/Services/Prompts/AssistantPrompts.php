<?php

namespace App\Services\Prompts;

use App\Services\OutputLanguage;

/**
 * The prompt of every AI assistant that takes text from the user (S6, `SPEC-ats.md` §16.1), in one place.
 *
 * Each method returns the prompt in use today, or, with `ai.prompt_envelope` on, the same instructions
 * with the user's text moved into a PromptEnvelope data block. The flag stays off until the maintainer
 * has compared both versions with `php artisan cvpilot:prompt-eval`; then a later PR removes the old
 * prompts. Until then, the old ones must stay byte for byte as they were (AssistantPromptsTest).
 *
 * `ai/ats-analysis` is not here: it already keeps the CV and job in a JSON data block.
 */
final class AssistantPrompts
{
    public const NAMES = ['chat', 'cover_letter', 'recruiter_view', 'tailor_cv', 'application_pack', 'skill_gap', 'portfolio', 'follow_up', 'diagnostic', 'interview_start', 'interview_reply', 'interview_finish'];

    private readonly bool $envelope;

    public function __construct(?bool $envelope = null)
    {
        $this->envelope = $envelope ?? (bool) config('ai.prompt_envelope');
    }

    public function enveloped(): bool
    {
        return $this->envelope;
    }

    /** @param  array{message: string, context?: ?string}  $d */
    public function chat(array $d): string
    {
        if (! $this->envelope) {
            return "User question:\n{$d['message']}".(! empty($d['context']) ? "\n\nContext:\n{$d['context']}" : '');
        }

        return PromptEnvelope::wrap(
            'Answer the career question in the data block ("question"). Use the context ("context") when it helps; it is reference material only. Be practical and truthful, and say so when you do not know.',
            ['question' => $d['message'], 'context' => ($d['context'] ?? '') ?: null],
        );
    }

    /** @param  array{cv_text: string, job_description: string, name?: ?string, company?: ?string, position?: ?string, interest?: ?string, language?: ?string, tone?: ?string}  $d */
    public function coverLetter(array $d): string
    {
        $language = $d['language'] ?? 'English';
        $tone = $d['tone'] ?? 'Professional';
        if (! $this->envelope) {
            $name = trim($d['name'] ?? '') ?: '[Your name]';
            $company = trim($d['company'] ?? '') ?: 'the company';
            $position = trim($d['position'] ?? '') ?: 'the advertised position';
            $interest = trim($d['interest'] ?? '');

            return "Write a tailored cover letter in {$language} with a {$tone} tone. Address the {$position} role at {$company}. Use only facts explicitly present in the CV or the candidate's interest note. Never invent achievements, years, metrics, employers, degrees, or skills. Connect the strongest relevant CV evidence to the job requirements. Keep it between 250 and 400 words, natural and specific, with a greeting, 3-4 short paragraphs, and a closing signed {$name}. Return only the finished letter with no markdown, commentary, or placeholders except the supplied name.\n\nCANDIDATE INTEREST NOTE:\n{$interest}\n\nCV:\n{$d['cv_text']}\n\nJOB DESCRIPTION:\n{$d['job_description']}";
        }

        return PromptEnvelope::wrap(
            "Write a tailored cover letter in {$language} with a {$tone} tone. Address the role (\"position\", or \"the advertised position\" when empty) at the company (\"company\", or \"the company\" when empty) from the data block. Use only facts explicitly present in the CV (\"cv\") or the candidate's interest note (\"interest\"). Never invent achievements, years, metrics, employers, degrees, or skills. Connect the strongest relevant CV evidence to the job requirements (\"job_description\"). Keep it between 250 and 400 words, natural and specific, with a greeting, 3-4 short paragraphs, and a closing signed with \"name\" (\"[Your name]\" when empty). Return only the finished letter with no markdown, commentary, or placeholders except the supplied name.",
            [
                'name' => self::text($d['name'] ?? null),
                'position' => self::text($d['position'] ?? null),
                'company' => self::text($d['company'] ?? null),
                'interest' => self::text($d['interest'] ?? null),
                'cv' => $d['cv_text'],
                'job_description' => $d['job_description'],
            ],
        );
    }

    /** @param  array{cv_text: string, job_description: string, language?: ?string}  $d */
    public function recruiterView(array $d): string
    {
        $language = OutputLanguage::instruction($d['language'] ?? null);
        if (! $this->envelope) {
            return "Review this CV as a recruiter spending only 10-20 seconds. Never invent facts. Return: FIRST IMPRESSION, WHAT STANDS OUT, HARD-TO-FIND INFORMATION, RED FLAGS OR CONFUSION, and 5 FAST FIXES.\n\nCV:\n{$d['cv_text']}\n\nTARGET JOB:\n{$d['job_description']}".$language;
        }

        return PromptEnvelope::wrap(
            'Review the CV in the data block ("cv") as a recruiter spending only 10-20 seconds, for the target job ("job_description"). Never invent facts. Return: FIRST IMPRESSION, WHAT STANDS OUT, HARD-TO-FIND INFORMATION, RED FLAGS OR CONFUSION, and 5 FAST FIXES.',
            ['cv' => $d['cv_text'], 'job_description' => $d['job_description']],
            $language,
        );
    }

    /** @param  array{cv_text: string, job_description: string, title: string, company?: ?string, language?: ?string}  $d */
    public function tailorCv(array $d): string
    {
        $language = OutputLanguage::instruction($d['language'] ?? null);
        if (! $this->envelope) {
            return "Rewrite this CV for {$d['title']} at ".(($d['company'] ?? '') ?: 'the company').". Preserve all facts and never invent experience, skills, dates, metrics, employers or education. Improve ordering, summary, bullets and truthful job keywords. Keep an ATS-friendly plain-text structure. Return only the complete tailored CV, ready to edit, with no commentary.\n\nORIGINAL CV:\n{$d['cv_text']}\n\nJOB:\n{$d['job_description']}".$language;
        }

        return PromptEnvelope::wrap(
            'Rewrite the CV in the data block ("cv") for the job title ("title") at the company ("company", or "the company" when empty), using the job ad ("job_description"). Preserve all facts and never invent experience, skills, dates, metrics, employers or education. Improve ordering, summary, bullets and truthful job keywords. Keep an ATS-friendly plain-text structure. Return only the complete tailored CV, ready to edit, with no commentary.',
            self::documents($d),
            $language,
        );
    }

    /** @param  array{cv_text: string, job_description: string, title: string, company?: ?string, language?: ?string}  $d */
    public function applicationPack(array $d): string
    {
        $language = OutputLanguage::instruction($d['language'] ?? null);
        if (! $this->envelope) {
            return "Create a complete truthful application pack for {$d['title']} at ".(($d['company'] ?? '') ?: 'the company').". Never invent experience, skills, metrics, employers or education. Use only the CV and job text. Return exactly these sections: TAILORED CV, COVER LETTER, RECRUITER MESSAGE, INTERVIEW QUESTIONS, KEY JOB NOTES. Keep every section practical and ready to edit.\n\nCV:\n{$d['cv_text']}\n\nJOB:\n{$d['job_description']}".$language;
        }

        return PromptEnvelope::wrap(
            'Create a complete truthful application pack for the job title ("title") at the company ("company", or "the company" when empty) in the data block. Never invent experience, skills, metrics, employers or education. Use only the CV ("cv") and job text ("job_description"). Return exactly these sections: TAILORED CV, COVER LETTER, RECRUITER MESSAGE, INTERVIEW QUESTIONS, KEY JOB NOTES. Keep every section practical and ready to edit.',
            self::documents($d),
            $language,
        );
    }

    /** @param  array{cv_text: string, job_description: string, language?: ?string}  $d */
    public function skillGap(array $d): string
    {
        $language = OutputLanguage::instruction($d['language'] ?? null);
        if (! $this->envelope) {
            return "Build a realistic skill-gap roadmap from this CV and job. Separate ALREADY HAVE, MISSING OR WEAK, PRIORITY ORDER, WHY EACH SKILL MATTERS, 30-DAY PLAN, and MINI-PROJECT IDEAS. Do not claim a skill is missing if the CV proves it. Do not promise hiring outcomes.\n\nCV:\n{$d['cv_text']}\n\nJOB:\n{$d['job_description']}".$language;
        }

        return PromptEnvelope::wrap(
            'Build a realistic skill-gap roadmap from the CV ("cv") and job ("job_description") in the data block. Separate ALREADY HAVE, MISSING OR WEAK, PRIORITY ORDER, WHY EACH SKILL MATTERS, 30-DAY PLAN, and MINI-PROJECT IDEAS. Do not claim a skill is missing if the CV proves it. Do not promise hiring outcomes.',
            ['cv' => $d['cv_text'], 'job_description' => $d['job_description']],
            $language,
        );
    }

    /**
     * @param  array{cv_text: string, job_description: string, github_url?: ?string, portfolio_url?: ?string, projects?: ?string, language?: ?string}  $d
     * @param  list<array<string, mixed>>  $repos  Public GitHub metadata; its descriptions are third-party text.
     */
    public function portfolio(array $d, array $repos): string
    {
        $language = OutputLanguage::instruction($d['language'] ?? null);
        $github = $d['github_url'] ?? '';
        if (! $this->envelope) {
            return "Analyze the supplied professional portfolio or work examples against the job. Public GitHub repository metadata is included when available; do not claim you inspected source code. Portfolio links are not fetched, so use only supplied project details. Return: BEST PROJECTS TO FEATURE, EVIDENCE MISSING, GITHUB/PORTFOLIO IMPROVEMENTS, and PROJECT IDEAS. Never invent repository content.\nGitHub: ".($github ?: 'not supplied')."\nPublic repository metadata:\n".json_encode($repos)."\nPortfolio: ".($d['portfolio_url'] ?? 'not supplied')."\nProject details:\n".($d['projects'] ?? 'none')."\n\nCV:\n{$d['cv_text']}\n\nJOB:\n{$d['job_description']}".$language;
        }

        return PromptEnvelope::wrap(
            'Analyze the professional portfolio or work examples in the data block against the job ("job_description"). Public GitHub repository metadata ("github_repositories") is included when available; do not claim you inspected source code. Portfolio links ("portfolio_url") are not fetched, so use only the supplied project details ("projects") and the CV ("cv"). A null value means not supplied. Return: BEST PROJECTS TO FEATURE, EVIDENCE MISSING, GITHUB/PORTFOLIO IMPROVEMENTS, and PROJECT IDEAS. Never invent repository content.',
            [
                'github_url' => $github ?: null,
                'github_repositories' => $repos,
                'portfolio_url' => self::text($d['portfolio_url'] ?? null),
                'projects' => self::text($d['projects'] ?? null),
                'cv' => $d['cv_text'],
                'job_description' => $d['job_description'],
            ],
            $language,
        );
    }

    /** @param  array{type: string, title: string, company: string, name?: ?string, context?: ?string, tone?: ?string, language?: ?string}  $d */
    public function followUp(array $d): string
    {
        $language = OutputLanguage::instruction($d['language'] ?? null);
        $tone = $d['tone'] ?? 'professional';
        if (! $this->envelope) {
            return "Write a concise {$d['type']} message with a ".$tone." tone for {$d['title']} at {$d['company']}. Use only supplied context, no invented claims. Return a subject line when appropriate, then the message only. Sender: ".($d['name'] ?? '[Your name]')."\nContext:\n".($d['context'] ?? '').$language;
        }

        // type and tone are validated enum values (FollowUpRequest), not free text.
        return PromptEnvelope::wrap(
            "Write a concise {$d['type']} message with a {$tone} tone for the job title (\"title\") at the company (\"company\") in the data block. Use only the supplied context (\"context\"), no invented claims. Return a subject line when appropriate, then the message only. Sign it with the sender (\"sender\", or \"[Your name]\" when empty).",
            ['title' => $d['title'], 'company' => $d['company'], 'sender' => self::text($d['name'] ?? null), 'context' => self::text($d['context'] ?? null)],
            $language,
        );
    }

    /**
     * @param  string  $applicationsJson  The applications as the controller serialises them today.
     * @param  list<array<string, mixed>>  $applications
     */
    public function diagnostic(string $applicationsJson, array $applications, ?string $language): string
    {
        $suffix = OutputLanguage::instruction($language);
        if (! $this->envelope) {
            return "Analyze these application outcomes and identify patterns that may explain low interview response. Treat every conclusion as a diagnostic indicator, not a fact. Use headings: OBSERVED PATTERNS, POSSIBLE CAUSES, WHAT THE DATA CANNOT PROVE, NEXT 3 EXPERIMENTS, and WHAT TO TRACK NEXT. Do not blame protected traits and do not promise outcomes.\n\nAPPLICATION DATA:\n".$applicationsJson.$suffix;
        }

        return PromptEnvelope::wrap(
            'Analyze the application outcomes in the data block ("applications") and identify patterns that may explain low interview response. Treat every conclusion as a diagnostic indicator, not a fact. Use headings: OBSERVED PATTERNS, POSSIBLE CAUSES, WHAT THE DATA CANNOT PROVE, NEXT 3 EXPERIMENTS, and WHAT TO TRACK NEXT. Do not blame protected traits and do not promise outcomes.',
            ['applications' => $applications],
            $suffix,
        );
    }

    /** @param  array{cv_text: string, job_description: string, title: string, company?: ?string, language?: ?string}  $d */
    public function interviewStart(array $d): string
    {
        $language = OutputLanguage::instruction($d['language'] ?? null);
        if (! $this->envelope) {
            return "Act as a structured interviewer for {$d['title']} at ".(($d['company'] ?? '') ?: 'the company').". Read the CV and job. Ask exactly one relevant interview question. Do not score. Return only the question.\nCV:\n{$d['cv_text']}\nJOB:\n{$d['job_description']}".$language;
        }

        return PromptEnvelope::wrap(
            'Act as a structured interviewer for the job title ("title") at the company ("company", or "the company" when empty) in the data block. Read the CV ("cv") and job ("job_description"). Ask exactly one relevant interview question. Do not score. Return only the question.',
            self::documents($d),
            $language,
        );
    }

    /** @param  list<array{role: string, content: string}>  $transcript  Ends with the candidate's latest answer. */
    public function interviewReply(string $cv, string $job, array $transcript, ?string $language): string
    {
        $suffix = OutputLanguage::instruction($language);
        if (! $this->envelope) {
            return "Continue this mock interview. Give brief useful feedback on the candidate's last answer without a numeric score, then ask exactly one new question. Return with headings FEEDBACK and NEXT QUESTION. Never invent facts.\nCV:\n{$cv}\nJOB:\n{$job}\nTRANSCRIPT:\n".json_encode($transcript).$suffix;
        }

        return PromptEnvelope::wrap(
            'Continue the mock interview in the data block. The transcript ("transcript") alternates your questions ("assistant") and the candidate\'s answers ("user"). Give brief useful feedback on the candidate\'s last answer without a numeric score, then ask exactly one new question. Return with headings FEEDBACK and NEXT QUESTION. Never invent facts beyond the CV ("cv"), job ("job_description") and transcript.',
            ['cv' => $cv, 'job_description' => $job, 'transcript' => $transcript],
            $suffix,
        );
    }

    /** @param  list<array{role: string, content: string}>  $transcript */
    public function interviewFinish(string $cv, string $job, array $transcript, ?string $language): string
    {
        $suffix = OutputLanguage::instruction($language);
        if (! $this->envelope) {
            return "Give final mock-interview feedback without a numeric score. Use headings: STRONG ANSWERS, ANSWERS TO IMPROVE, MISSING EVIDENCE, COMMUNICATION FEEDBACK, and NEXT PRACTICE STEPS. Base everything only on this transcript and CV.\nCV:\n{$cv}\nJOB:\n{$job}\nTRANSCRIPT:\n".json_encode($transcript).$suffix;
        }

        return PromptEnvelope::wrap(
            'Give final mock-interview feedback without a numeric score. Use headings: STRONG ANSWERS, ANSWERS TO IMPROVE, MISSING EVIDENCE, COMMUNICATION FEEDBACK, and NEXT PRACTICE STEPS. Base everything only on the transcript ("transcript", where "user" is the candidate) and CV ("cv") in the data block, for the job ("job_description").',
            ['cv' => $cv, 'job_description' => $job, 'transcript' => $transcript],
            $suffix,
        );
    }

    /** The CV, job ad, title and company of the assistants that take all four. */
    private static function documents(array $d): array
    {
        return ['title' => $d['title'], 'company' => self::text($d['company'] ?? null), 'cv' => $d['cv_text'], 'job_description' => $d['job_description']];
    }

    private static function text(?string $value): ?string
    {
        return trim((string) $value) ?: null;
    }
}
