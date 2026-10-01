<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Jobs\JobLinksRequest;
use App\Http\Requests\Jobs\SaveJobSearchRequest;
use App\Http\Requests\Jobs\SearchJobsRequest;
use App\Models\CvDocument;
use App\Models\JobSearch;
use App\Services\AtsScorer;
use App\Services\JobLocation;
use App\Services\JobSearchService;
use App\Support\Redactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class JobsController
{
    public function search(SearchJobsRequest $r, JobSearchService $jobs, AtsScorer $ats)
    {
        $d = $r->validated();

        $started = microtime(true);

        try {
            $result = $jobs->search($d);
            $cv = $d['cv_text'] ?? null;
            if (! $cv && ! empty($d['cv_document_id'])) {
                $cv = CvDocument::where('user_id', $r->user()->id)->findOrFail($d['cv_document_id'])->extracted_text;
            }

            $country = strtoupper(trim((string) ($d['country'] ?? '')));

            $items = collect($result['data'])->filter(function ($job) use ($d) {
                $mode = $d['work_mode'] ?? 'any';
                if ($mode !== 'any' && ($job['work_mode'] ?? ($job['remote'] ? 'remote' : 'onsite')) !== $mode) {
                    return false;
                }
                if (($d['contract'] ?? 'any') !== 'any' && ($job['contract_type'] ?? null) !== $d['contract']) {
                    return false;
                }
                if (! empty($d['salary_min']) && (! isset($job['salary_min']) || (float) $job['salary_min'] < (float) $d['salary_min'])) {
                    return false;
                }
                if (! empty($d['visa_only']) && empty($job['visa_sponsorship'])) {
                    return false;
                }
                if (! empty($d['language']) && ! str_contains(strtolower(($job['title'] ?? '').' '.($job['description'] ?? '')), strtolower($d['language']))) {
                    return false;
                }

                if (! JobLocation::matches($job, $d)) {
                    return false;
                }

                return true;
            })->take($d['limit'] ?? 100)->map(function ($job) use ($cv, $ats) {
                $job['match'] = $cv ? $ats->score($cv, $job['title'].' '.$job['description']) : null;

                return $job;
            })->values();

            $result['data'] = $items;
            $result['count'] = $items->count();
            $result['limit'] = $d['limit'] ?? 100;

            $this->logSearch($r, $d, $result, (int) ((microtime(true) - $started) * 1000), true);

            return $result;
        } catch (\Throwable $e) {
            $this->logSearch($r, $d, ['provider' => $d['provider'] ?? null, 'data' => []], (int) ((microtime(true) - $started) * 1000), false, Redactor::scrub($e->getMessage()));
            throw $e;
        }
    }

    public function links(JobLinksRequest $r)
    {
        $d = $r->validated();

        $q = urlencode($d['q']);
        $location = trim($d['city'] ?? $d['location'] ?? '');
        if (strtoupper($d['country'] ?? '') === 'MA') {
            $location = $location === '' ? 'Morocco' : $location;
            if (! preg_match('/\b(morocco|maroc)\b/i', $location)) {
                $location .= ', Morocco';
            }
        }
        $l = urlencode($location);
        $country = strtoupper(trim((string) ($d['country'] ?? '')));

        $links = [
            'linkedin' => "https://www.linkedin.com/jobs/search/?keywords=$q&location=$l",
            'indeed' => ($country === 'MA' ? 'https://ma.indeed.com' : 'https://www.indeed.com')."/jobs?q=$q&l=$l",
            'google' => "https://www.google.com/search?q=$q+jobs+$l",
            'remoteok' => "https://remoteok.com/remote-$q-jobs",
        ];

        // Add local top career portals based on target country
        if ($country === 'MA') {
            if (empty($d['city']) && empty($d['location'])) {
                $links['linkedin'] .= '&geoId=102787409';
            }
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

    public function saved(Request $r)
    {
        return JobSearch::where('user_id', $r->user()->id)->latest()->limit(30)->get();
    }

    public function save(SaveJobSearchRequest $r)
    {
        $d = $r->validated();
        $d['user_id'] = $r->user()->id;

        return response()->json(JobSearch::create($d), 201);
    }

    public function destroySaved(Request $r, JobSearch $search)
    {
        Gate::forUser($r->user())->authorize('delete', $search);
        $search->delete();

        return response()->noContent();
    }

    private function logSearch(Request $r, array $d, array $result, int $latency, bool $success, ?string $error = null): void
    {
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
        } catch (\Throwable) {
        }
    }
}
