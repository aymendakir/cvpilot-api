<?php

namespace App\Http\Controllers\Api\V1\Ats;

use App\Http\Requests\Ats\StoreAtsAnalysisRequest;
use App\Http\Resources\AtsReportResource;
use App\Services\Ats\AtsAnalyzer;
use App\Services\Ats\Parsing\UnreadableDocument;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/v1/ats/analyses (SPEC-ats.md §5). Stateless: the upload is read in place from PHP's temp
 * file and nothing is stored. One log line per analysis without any CV content (S4 decision 30).
 */
class AnalysisController
{
    public function store(StoreAtsAnalysisRequest $request, AtsAnalyzer $analyzer): AtsReportResource
    {
        $input = $request->validated();
        $started = hrtime(true);
        $job = $input['job_description'] ?? null;
        $locale = $input['locale'] ?? null;
        $includeText = (bool) ($input['include_text'] ?? false);

        try {
            $report = $request->hasFile('file')
                ? $analyzer->analyzeFile((string) $request->file('file')->getRealPath(), (string) $request->file('file')->getClientOriginalName(), $job, $locale, $includeText)
                : $analyzer->analyzeText($input['cv_text'], $job, $locale, $includeText);
        } catch (UnreadableDocument $e) {
            Log::info('ats.analysis', ['outcome' => 'refused', 'reason' => $e->reason, 'duration_ms' => $this->elapsed($started)]);

            throw ValidationException::withMessages(['file' => [$e->reason]]);
        }

        $body = $report->toArray();
        Log::info('ats.analysis', [
            'outcome' => $body['score_status'],
            'mode' => $body['mode'],
            'type' => $body['document']['type'],
            'pages' => $body['document']['pages'],
            'duration_ms' => $this->elapsed($started),
        ]);

        return new AtsReportResource($report);
    }

    private function elapsed(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1e6);
    }
}
