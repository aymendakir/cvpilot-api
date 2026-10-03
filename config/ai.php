<?php

/*
 * AI prompts (S6, `SPEC-ats.md` §16.1). The prompt envelope keeps text the user supplied out of the
 * instructions. It stays off until the maintainer has compared the outputs (`php artisan cvpilot:prompt-eval`).
 */

return [
    'prompt_envelope' => (bool) env('AI_PROMPT_ENVELOPE', false),
];
