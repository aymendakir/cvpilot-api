# Implementation Plan: API-C — the career assistants answer in the user's language

Why: the M5 landing pages (cv-ai PR #41) found that the seven career assistants never say which language to write in, so they usually answer in English, even for a French CV. The French pages had to say so. The cover letter already takes a `language`. This phase gives the other assistants the same option. The API-B plan is in git history (`tasks/plan.md` at 7354927).

Branch: `feat/api-tool-locale` (from `main` at 7354927). One PR. The app sends the language in the next phase (cv-ai), after this one is merged.

## Contract

An optional `language` field, with the cover letter's values: `English`, `French`, `Spanish` or `Arabic`. Anything else is a 422 on `language`.

| Route | Where the language goes |
| --- | --- |
| `POST ai/recruiter-view`, `ai/tailor-cv`, `ai/application-pack`, `ai/skill-gap`, `ai/portfolio-review`, `ai/follow-up`, `ai/career-diagnostic` | appended to that one prompt |
| `POST interviews` | stored on the session (`interview_sessions.language`) and used by every `reply` and the `finish` |

- **With a language**, the prompt ends with: "OUTPUT LANGUAGE: Write the whole answer in {language}, headings included. Keep names, quoted source text and technical terms as they are."
- **Without one**, the prompts are byte for byte what they were. No behaviour changes for current clients.
- The language is saved with the result (`career_reports.input.language`) and returned on the interview (`language`).

## Tasks

1. `App\Services\OutputLanguage` (the validation rule and the instruction). Add the rule to `DocumentsRequest`, `PortfolioReviewRequest` and `FollowUpRequest`, and add a new `DiagnosticRequest`.
2. The prompts in `CareerAiController` and `InterviewController`. A repeatable migration for `interview_sessions.language`, and the field on `InterviewSessionResource`.
3. `tests/Feature/Api/OutputLanguageTest.php`:
   - each route writes in the language asked for;
   - without a language, no instruction is sent;
   - a 422 for an unknown value, with nothing sent;
   - the language is kept with the saved result;
   - an interview keeps its language for every turn;
   - the diagnostic honours it.
4. Pint, then the full suite.
