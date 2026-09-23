<?php
namespace App\Http\Controllers;

use App\Models\{CvDocument, JobSearch};
use App\Services\{JobSearchService, AtsScorer};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JobsController {
    public function search(Request $r, JobSearchService $jobs, AtsScorer $ats) {
        $d = $r->validate([
            'q' => 'required|string|max:120',
            'country' => 'required|string|max:10',
            'country_name' => 'nullable|string|max:120',
            'city' => 'nullable|string|max:120',
            'date' => 'nullable|in:today,3days,week,month,all',
            'provider' => 'nullable|in:jsearch,adzuna,jooble,arbeitnow,remotive,jobicy',
            'pages' => 'nullable|integer|min:1|max:5',
            'limit' => 'nullable|integer|min:1|max:100',
            'work_mode' => 'nullable|in:any,remote,hybrid,onsite',
            'contract' => 'nullable|in:any,full_time,part_time,contract,internship,freelance',
            'salary_min' => 'nullable|numeric|min:0',
            'language' => 'nullable|string|max:40',
            'visa_only' => 'nullable|boolean',
            'cv_document_id' => 'nullable|integer',
            'cv_text' => 'nullable|string|min:30|max:30000',
        ]);

        $started = microtime(true);

        try {
            $result = $jobs->search($d);
            $cv = $d['cv_text'] ?? null;
            if (!$cv && !empty($d['cv_document_id'])) {
                $cv = CvDocument::where('user_id', $r->user()->id)->findOrFail($d['cv_document_id'])->extracted_text;
            }

            $country = strtoupper(trim((string)($d['country'] ?? '')));

            $items = collect($result['data'])->filter(function ($job) use ($d, $country) {
                $mode = $d['work_mode'] ?? 'any';
                if ($mode !== 'any' && ($job['work_mode'] ?? ($job['remote'] ? 'remote' : 'onsite')) !== $mode) {
                    return false;
                }
                if (($d['contract'] ?? 'any') !== 'any' && ($job['contract_type'] ?? null) !== $d['contract']) {
                    return false;
                }
                if (!empty($d['salary_min']) && (!isset($job['salary_min']) || (float)$job['salary_min'] < (float)$d['salary_min'])) {
                    return false;
                }
                if (!empty($d['visa_only']) && empty($job['visa_sponsorship'])) {
                    return false;
                }
                if (!empty($d['language']) && !str_contains(strtolower(($job['title'] ?? '') . ' ' . ($job['description'] ?? '')), strtolower($d['language']))) {
                    return false;
                }

                // If user selected a specific non-German country (e.g. Morocco, France, US), exclude strict on-site German listings
                if ($country !== 'DE' && $country !== 'ALL' && !empty($country)) {
                    if (($job['source'] ?? '') === 'Arbeitnow' && empty($job['remote']) && ($job['work_mode'] ?? '') === 'onsite') {
                        return false;
                    }
                }

                return true;
            })->take($d['limit'] ?? 100)->map(function ($job) use ($cv, $ats) {
                $job['match'] = $cv ? $ats->score($cv, $job['title'] . ' ' . $job['description']) : null;
                return $job;
            })->values();

            $result['data'] = $items;
            $result['count'] = $items->count();
            $result['limit'] = $d['limit'] ?? 100;

            $this->logSearch($r, $d, $result, (int)((microtime(true) - $started) * 1000), true);
            return $result;
        } catch (\Throwable $e) {
            $this->logSearch($r, $d, ['provider' => $d['provider'] ?? null, 'data' => []], (int)((microtime(true) - $started) * 1000), false, $e->getMessage());
            throw $e;
        }
    }

    public function links(Request $r) {
        $d = $r->validate([
            'q' => 'required|string|max:120',
            'location' => 'nullable|string|max:180',
            'country' => 'nullable|string|max:10',
        ]);

        $q = urlencode($d['q']);
        $l = urlencode($d['location'] ?? '');
        $country = strtoupper(trim((string)($d['country'] ?? '')));

        $links = [
            'linkedin' => "https://www.linkedin.com/jobs/search/?keywords=$q&location=$l",
            'indeed' => "https://www.indeed.com/jobs?q=$q&l=$l",
            'google' => "https://www.google.com/search?q=$q+jobs+$l",
            'remoteok' => "https://remoteok.com/remote-$q-jobs",
        ];

        // Add local top career portals based on target country
        if ($country === 'MA') {
            $links['rekrute'] = "https://www.rekrute.com/offres-emploi-maroc.html?keyword=$q";
            $links['bayt'] = "https://www.bayt.com/en/morocco/jobs/?q=$q";
        } elseif ($country === 'FR') {
            $links['welcometothejungle'] = "https://www.welcometothejungle.com/fr/jobs?query=$q";
            $links['apec'] = "https://www.apec.fr/candidat/recherche-emploi.html/emploi?motsCles=$q";
        } elseif ($country === 'DE') {
            $links['stepstone'] = "https://www.stepstone.de/jobs/$q";
            $links['arbeitnow'] = "https://www.arbeitnow.com/jobs/search?q=$q";
        }

        return $links;
    }

    public function saved(Request $r) {
        return JobSearch::where('user_id', $r->user()->id)->latest()->limit(30)->get();
    }

    public function save(Request $r) {
        $d = $r->validate([
            'name' => 'required|string|max:120',
            'query' => 'required|string|max:120',
            'country' => 'required|string|max:10',
            'country_name' => 'nullable|string|max:120',
            'city' => 'nullable|string|max:120',
            'experience' => 'nullable|string|max:30',
            'work_mode' => 'nullable|in:any,remote,hybrid,onsite',
            'filters' => 'nullable|array',
            'alerts_enabled' => 'nullable|boolean',
            'alert_frequency' => 'nullable|in:daily,weekly',
        ]);
        $d['user_id'] = $r->user()->id;
        return response()->json(JobSearch::create($d), 201);
    }

    public function destroySaved(Request $r, JobSearch $search) {
        abort_unless($search->user_id === $r->user()->id, 404);
        $search->delete();
        return response()->noContent();
    }

    private function logSearch(Request $r, array $d, array $result, int $latency, bool $success, ?string $error = null): void {
        try {
            DB::table('job_search_events')->insert([
                'user_id' => $r->user()->id,
                'provider' => $result['provider'] ?? null,
                'query' => $d['q'],
                'country' => $d['country'] ?? null,
                'city' => $d['city'] ?? null,
                'results_count' => count($result['data'] ?? []),
                'latency_ms' => $latency,
                'success' => $success,
                'error' => $error ? mb_substr($error, 0, 500) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable) {}
    }
}
