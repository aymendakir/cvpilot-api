<?php

/*
 * Anonymous routes (Phase 4 API-A, `cv-ai/SPEC.md` §18.5): the ATS check and file reading without an
 * account. Turnstile, per-IP limits and a global daily cap; every number can change without a deploy.
 */

$list = fn (?string $value) => array_values(array_filter(array_map('trim', explode(',', (string) $value))));

return [
    'turnstile' => [
        // Required outside local/testing: without it the public routes answer 503 (fail closed).
        'secret' => env('TURNSTILE_SECRET_KEY'),
        // Hostnames the widget may run on; empty accepts any hostname Cloudflare reports.
        'hostnames' => $list(env('TURNSTILE_HOSTNAMES')),
        'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        'timeout' => 5,
    ],

    // Read the visitor's address from CF-Connecting-IP. Only when the origin accepts Cloudflare traffic
    // alone (docs/DEPLOYMENT.md): otherwise anyone can set the header (API-A decision D3).
    'trust_cf_connecting_ip' => (bool) env('TRUST_CF_CONNECTING_IP', false),

    'limits' => [
        'ats_per_minute' => (int) env('PUBLIC_ATS_PER_MINUTE', 5),
        'ats_per_day' => (int) env('PUBLIC_ATS_PER_DAY', 30),
        // All anonymous ATS checks together; 0 = no cap.
        'ats_global_per_day' => (int) env('PUBLIC_ATS_GLOBAL_PER_DAY', 5000),
        'extract_per_minute' => (int) env('PUBLIC_EXTRACT_PER_MINUTE', 10),
        'extract_per_day' => (int) env('PUBLIC_EXTRACT_PER_DAY', 60),
        // The four anonymous AI tools share one budget per visitor (API-D).
        'ai_per_minute' => (int) env('PUBLIC_AI_PER_MINUTE', 3),
        'ai_per_day' => (int) env('PUBLIC_AI_PER_DAY', 10),
        // All anonymous AI answers together, the daily cost ceiling; 0 = no cap.
        'ai_global_per_day' => (int) env('PUBLIC_AI_GLOBAL_PER_DAY', 300),
    ],

    // false: the anonymous AI routes answer 503 (switch them off without touching the frontend).
    'ai_enabled' => (bool) env('PUBLIC_AI_ENABLED', true),
];
