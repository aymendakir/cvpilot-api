<?php
namespace App\Services;

use App\Models\Integration;
use Illuminate\Support\Facades\{Cache, Http};

class JobSearchService {
    private const ADZUNA_COUNTRIES = ['gb', 'us', 'at', 'au', 'be', 'br', 'ca', 'ch', 'de', 'es', 'fr', 'in', 'it', 'mx', 'nl', 'nz', 'pl', 'ru', 'sg', 'za'];
    public function search(array $d): array {
        $providers = $this->providers($d);
        $cacheInput = $d;
        unset($cacheInput['cv_text'], $cacheInput['cv_document_id']);
        $key = 'jobs:location-v2:' . sha1(json_encode([$cacheInput, $providers->pluck('provider')->all()]));

        return Cache::remember($key, now()->addMinutes(5), function () use ($d, $providers) {
            $attempts = [];
            $allJobs = [];
            $limit = min(100, max(1, (int)($d['limit'] ?? 100)));

            foreach ($providers as $cfg) {
                try {
                    $data = $this->collectPages($cfg, $d);
                    $attempts[] = ['provider' => $cfg->provider, 'ok' => true, 'count' => count($data)];
                    if (!empty($data)) {
                        $allJobs = array_merge($allJobs, $data);
                        // If we have collected enough matching offers, return early
                        if (count($allJobs) >= $limit) {
                            break;
                        }
                    }
                } catch (\Throwable $e) {
                    $attempts[] = ['provider' => $cfg->provider, 'ok' => false, 'count' => 0,
                        'status' => $e instanceof \Illuminate\Http\Client\RequestException ? $e->response->status() : null];
                }
            }

            // Deduplicate by URL or normalized Title + Company
            $uniqueJobs = collect($allJobs)->unique(function ($j) {
                return strtolower(trim($j['url'])) ?: strtolower(($j['title'] ?? '') . '|' . ($j['company'] ?? ''));
            })->take($limit)->values()->all();

            $activeProvider = !empty($uniqueJobs)
                ? implode(', ', array_unique(array_column($uniqueJobs, 'source')))
                : '';

            $sourceFailed = collect($attempts)->contains(fn ($attempt) => !$attempt['ok']);
            return [
                'provider' => $activeProvider,
                'data' => $uniqueJobs,
                'attempts' => $attempts,
                'message' => empty($uniqueJobs)
                    ? ($sourceFailed ? 'A connected job source is unavailable. Search Indeed or LinkedIn directly while its API access is restored.'
                        : 'No imported offers matched this location. Search Indeed or LinkedIn directly with the same role and city.')
                    : null,
                'cached_for_seconds' => 300,
            ];
        });
    }

    private function providers(array $d): \Illuminate\Support\Collection {
        $requested = $d['provider'] ?? null;
        $country = strtoupper(trim((string)($d['country'] ?? '')));

        // Check if database has enabled job integrations (e.g. JSearch, Adzuna, Jooble)
        $enabled = Integration::where('type', 'jobs')->where('enabled', true)->orderBy('priority')->orderBy('id')->get();

        $eligible = fn ($cfg) => !($cfg->provider === 'arbeitnow' && !in_array($country, ['DE', 'ALL'], true))
            && !($cfg->provider === 'adzuna' && !in_array(strtolower($country), self::ADZUNA_COUNTRIES, true));
        $enabled = $enabled->filter($eligible);

        if ($requested === 'arbeitnow' && !in_array($country, ['DE', 'ALL'], true)) return collect();
        if ($requested) {
            $chosen = $enabled->firstWhere('provider', $requested);
            if ($chosen) {
                return collect([$chosen]);
            }
            if (in_array($requested, ['remotive', 'jobicy', 'arbeitnow'], true)) {
                return collect([(object)['provider' => $requested, 'secret' => 'public', 'settings' => []]]);
            }
            return collect();
        }

        $list = collect();

        // Include any enabled custom integrations
        foreach ($enabled as $int) {
            $list->push($int);
        }

        // Morocco local searches use location-aware integrations, not unrelated remote feeds.
        if ($country === 'MA' && ($d['work_mode'] ?? 'any') !== 'remote') return $list->filter($eligible)->unique('provider')->values();

        if (in_array($country, ['DE', 'ALL'], true)) {
            $list->push((object)['provider' => 'arbeitnow', 'secret' => 'public', 'settings' => []]);
        }
        $list->push((object)['provider' => 'remotive', 'secret' => 'public', 'settings' => []]);
        $list->push((object)['provider' => 'jobicy', 'secret' => 'public', 'settings' => []]);

        return $list->filter($eligible)->unique('provider')->values();
    }

    private function collectPages($cfg, array $d): array {
        $pages = in_array($cfg->provider, ['remotive', 'jobicy'], true) ? 1 : min(3, max(1, (int)($d['pages'] ?? 2)));
        $limit = min(100, max(1, (int)($d['limit'] ?? 100)));
        $all = [];

        for ($page = 1; $page <= $pages && count($all) < $limit; $page++) {
            $pageData = $d;
            $pageData['page'] = $page;

            $result = match ($cfg->provider) {
                'remotive' => $this->remotive($pageData),
                'jobicy' => $this->jobicy($pageData),
                'jsearch' => $this->jsearch($cfg, $pageData),
                'adzuna' => $this->adzuna($cfg, $pageData),
                'jooble' => $this->jooble($cfg, $pageData),
                'arbeitnow' => $this->arbeitnow($pageData),
                default => [],
            };

            if (!$result) break;
            $all = array_merge($all, array_values(array_filter($result, fn ($job) => JobLocation::matches($job, $d))));
        }

        return collect($all)->filter(fn($j) => !empty($j['title']) && !empty($j['url']))->values()->all();
    }

    private function remotive(array $d): array {
        $q = trim($d['q'] ?? 'developer');
        $r = Http::timeout(15)->get('https://remotive.com/api/remote-jobs', [
            'search' => $q,
            'limit' => 30,
        ]);

        $r->throw();

        return collect($r->json('jobs', []))->map(function ($j) {
            $loc = $j['candidate_required_location'] ?? 'Location not specified';
            $isRemote = true;

            return $this->job([
                'external_id' => 'remotive-' . ($j['id'] ?? null),
                'title' => $j['title'] ?? '',
                'company' => $j['company_name'] ?? '',
                'location' => $loc,
                'description' => strip_tags($j['description'] ?? ''),
                'url' => $j['url'] ?? '',
                'remote' => $isRemote,
                'work_mode' => 'remote',
                'published_at' => $j['publication_date'] ?? null,
                'source' => 'Remotive',
                'tags' => $j['tags'] ?? [],
                'contract_type' => $this->contract($j['job_type'] ?? null),
                'salary_text' => $j['salary'] ?? null,
                'visa_sponsorship' => str_contains(strtolower($j['description'] ?? ''), 'visa sponsor'),
            ]);
        })->values()->all();
    }

    private function jobicy(array $d): array {
        $q = trim($d['q'] ?? 'developer');
        $terms = array_filter(preg_split('/\s+/', strtolower($q)), fn($x) => strlen($x) > 2);
        $primaryTag = reset($terms) ?: 'developer';

        $r = Http::timeout(15)->get('https://jobicy.com/api/v2/remote-jobs', [
            'count' => 30,
            'tag' => $primaryTag,
        ]);

        $r->throw();

        return collect($r->json('jobs', []))->map(function ($j) {
            $loc = $j['jobGeo'] ?? 'Location not specified';
            return $this->job([
                'external_id' => 'jobicy-' . ($j['id'] ?? null),
                'title' => $j['jobTitle'] ?? '',
                'company' => $j['companyName'] ?? '',
                'location' => $loc,
                'description' => strip_tags($j['jobExcerpt'] ?? $j['jobDescription'] ?? ''),
                'url' => $j['url'] ?? '',
                'remote' => true,
                'work_mode' => 'remote',
                'published_at' => $j['pubDate'] ?? null,
                'source' => 'Jobicy',
                'tags' => is_array($j['jobIndustry'] ?? null) ? $j['jobIndustry'] : [$j['jobIndustry'] ?? 'Engineering'],
                'contract_type' => $this->contract(is_array($j['jobType'] ?? null) ? implode(' ', $j['jobType']) : ($j['jobType'] ?? null)),
                'salary_min' => $j['annualSalaryMin'] ?? null,
                'salary_max' => $j['annualSalaryMax'] ?? null,
                'salary_currency' => $j['salaryCurrency'] ?? null,
                'visa_sponsorship' => str_contains(strtolower($j['jobExcerpt'] ?? ''), 'visa sponsor'),
            ]);
        })->values()->all();
    }

    private function jsearch($c, array $d): array {
        $country = strtoupper($d['country']);
        $locationName = $country === 'MA' ? 'Morocco' : ($country === 'ALL' ? 'Remote' : ($d['country_name'] ?? $country));
        $loc = trim(($d['city'] ?? '') . ' ' . $locationName);
        $query = trim($d['q'] . ($loc ? " in $loc" : ""));
        $params = [
            'query' => $query,
            'page' => $d['page'],
            'num_pages' => 1,
            'date_posted' => $d['date'] ?? 'month',
        ];
        if ($country !== 'ALL') $params['country'] = strtolower($country === 'GB' ? 'UK' : $country);
        if ($country === 'MA') $params['language'] = 'fr';
        if (($d['work_mode'] ?? 'any') === 'remote' || $country === 'ALL') $params['remote_jobs_only'] = 'true';

        $r = Http::timeout(25)->withHeaders([
            'X-RapidAPI-Key' => $c->secret,
            'X-RapidAPI-Host' => 'jsearch.p.rapidapi.com',
        ])->get('https://jsearch.p.rapidapi.com/search', $params);

        $r->throw();

        return collect($r->json('data', []))->map(fn($j) => $this->job([
            'external_id' => $j['job_id'] ?? null,
            'title' => $j['job_title'] ?? '',
            'company' => $j['employer_name'] ?? '',
            'location' => implode(', ', array_filter([$j['job_city'] ?? null, $j['job_state'] ?? null, $j['job_country'] ?? null])) ?: 'Location not specified',
            'description' => $j['job_description'] ?? '',
            'url' => $j['job_apply_link'] ?? $j['job_google_link'] ?? '',
            'remote' => (bool)($j['job_is_remote'] ?? false),
            'work_mode' => ($j['job_is_remote'] ?? false) ? 'remote' : $this->detectMode(($j['job_title'] ?? '') . ' ' . ($j['job_description'] ?? '')),
            'published_at' => $j['job_posted_at_datetime_utc'] ?? null,
            'source' => $j['job_publisher'] ?? 'JSearch',
            'country_code' => $j['job_country'] ?? null,
            'tags' => $j['job_required_skills'] ?? [],
            'contract_type' => $this->contract($j['job_employment_type'] ?? null),
            'salary_min' => $j['job_min_salary'] ?? null,
            'salary_max' => $j['job_max_salary'] ?? null,
            'salary_currency' => $j['job_salary_currency'] ?? null,
            'visa_sponsorship' => str_contains(strtolower($j['job_description'] ?? ''), 'visa sponsor'),
        ]))->values()->all();
    }

    private function adzuna($c, array $d): array {
        $settings = $c->settings ?? [];
        $appId = $settings['app_id'] ?? '';
        if (!$appId) return [];

        $cc = strtolower($d['country']);
        if (!in_array($cc, self::ADZUNA_COUNTRIES, true)) return [];

        $days = ['today' => 1, '3days' => 3, 'week' => 7, 'month' => 30, 'all' => null];
        $params = [
            'app_id' => $appId,
            'app_key' => $c->secret,
            'what' => $d['q'],
            'where' => $d['city'] ?? '',
            'results_per_page' => 20,
            'content-type' => 'application/json',
        ];
        if ($days[$d['date'] ?? 'month']) $params['max_days_old'] = $days[$d['date'] ?? 'month'];

        $r = Http::timeout(25)->get("https://api.adzuna.com/v1/api/jobs/$cc/search/{$d['page']}", $params);
        $r->throw();

        return collect($r->json('results', []))->map(fn($j) => $this->job([
            'external_id' => $j['id'] ?? null,
            'title' => $j['title'] ?? '',
            'company' => data_get($j, 'company.display_name', ''),
            'location' => data_get($j, 'location.display_name', ''),
            'description' => $j['description'] ?? '',
            'url' => $j['redirect_url'] ?? '',
            'remote' => str_contains(strtolower(($j['title'] ?? '') . ' ' . ($j['description'] ?? '')), 'remote'),
            'work_mode' => $this->detectMode(($j['title'] ?? '') . ' ' . ($j['description'] ?? '')),
            'published_at' => $j['created'] ?? null,
            'source' => 'Adzuna',
            'country_code' => strtoupper($cc),
            'contract_type' => $this->contract($j['contract_time'] ?? $j['contract_type'] ?? null),
            'salary_min' => $j['salary_min'] ?? null,
            'salary_max' => $j['salary_max'] ?? null,
            'visa_sponsorship' => str_contains(strtolower($j['description'] ?? ''), 'visa sponsor'),
        ]))->values()->all();
    }

    private function jooble($c, array $d): array {
        $r = Http::timeout(25)->post('https://jooble.org/api/' . rawurlencode($c->secret), [
            'keywords' => $d['q'],
            'location' => trim(($d['city'] ?? '') . ' ' . ($d['country'] === 'MA' ? 'Morocco' : ($d['country_name'] ?? $d['country']))),
            'page' => $d['page'],
        ]);
        $r->throw();

        return collect($r->json('jobs', []))->map(fn($j) => $this->job([
            'external_id' => $j['id'] ?? null,
            'title' => $j['title'] ?? '',
            'company' => $j['company'] ?? '',
            'location' => $j['location'] ?? '',
            'description' => strip_tags($j['snippet'] ?? ''),
            'url' => $j['link'] ?? '',
            'remote' => str_contains(strtolower(($j['title'] ?? '') . ' ' . ($j['location'] ?? '')), 'remote'),
            'work_mode' => $this->detectMode(($j['title'] ?? '') . ' ' . ($j['location'] ?? '') . ' ' . ($j['snippet'] ?? '')),
            'published_at' => $j['updated'] ?? null,
            'source' => $j['source'] ?? 'Jooble',
            'contract_type' => $this->contract($j['type'] ?? null),
            'salary_text' => $j['salary'] ?? null,
            'visa_sponsorship' => str_contains(strtolower($j['snippet'] ?? ''), 'visa sponsor'),
        ]))->values()->all();
    }

    private function arbeitnow(array $d): array {
        $r = Http::timeout(25)->get('https://www.arbeitnow.com/api/job-board-api', ['page' => $d['page']]);
        $r->throw();

        $terms = array_filter(preg_split('/\s+/', strtolower($d['q'])), fn($x) => strlen($x) > 2);

        return collect($r->json('data', []))->filter(function ($j) use ($terms) {
            $text = strtolower(($j['title'] ?? '') . ' ' . implode(' ', $j['tags'] ?? []) . ' ' . ($j['description'] ?? ''));
            foreach ($terms as $t) {
                if (str_contains($text, $t)) return true;
            }
            return false;
        })->map(fn($j) => $this->job([
            'external_id' => $j['slug'] ?? null,
            'title' => $j['title'] ?? '',
            'company' => $j['company_name'] ?? '',
            'location' => $j['location'] ?: 'Germany',
            'description' => strip_tags($j['description'] ?? ''),
            'url' => $j['url'] ?? '',
            'remote' => (bool)($j['remote'] ?? false),
            'work_mode' => ($j['remote'] ?? false) ? 'remote' : $this->detectMode(($j['title'] ?? '') . ' ' . ($j['description'] ?? '')),
            'published_at' => isset($j['created_at']) ? date(DATE_ATOM, $j['created_at']) : null,
            'source' => 'Arbeitnow',
            'tags' => $j['tags'] ?? [],
            'contract_type' => $this->contract($j['job_types'][0] ?? null),
            'visa_sponsorship' => str_contains(strtolower($j['description'] ?? ''), 'visa sponsor'),
        ]))->values()->all();
    }

    private function job(array $j): array {
        return array_merge([
            'external_id' => null,
            'title' => '',
            'company' => '',
            'location' => '',
            'description' => '',
            'url' => '',
            'remote' => false,
            'work_mode' => 'onsite',
            'published_at' => null,
            'source' => 'Unknown',
            'tags' => [],
            'contract_type' => null,
            'salary_min' => null,
            'salary_max' => null,
            'salary_currency' => null,
            'salary_text' => null,
            'visa_sponsorship' => false,
        ], $j);
    }

    private function detectMode(string $text): string {
        $text = strtolower($text);
        if (str_contains($text, 'remote') || str_contains($text, 'télétravail')) return 'remote';
        if (str_contains($text, 'hybrid') || str_contains($text, 'hybride')) return 'hybrid';
        return 'onsite';
    }

    private function contract(?string $value): ?string {
        $v = strtolower((string)$value);
        if (!$v) return null;
        if (str_contains($v, 'intern') || str_contains($v, 'stage')) return 'internship';
        if (str_contains($v, 'part') || str_contains($v, 'temps partiel')) return 'part_time';
        if (str_contains($v, 'freelance') || str_contains($v, 'indépendant')) return 'freelance';
        if (str_contains($v, 'contract') || str_contains($v, 'cdd')) return 'contract';
        if (str_contains($v, 'full') || str_contains($v, 'cdi') || str_contains($v, 'permanent')) return 'full_time';
        return null;
    }
}
