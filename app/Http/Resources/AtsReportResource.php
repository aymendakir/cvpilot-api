<?php

namespace App\Http\Resources;

use App\Services\Ats\Report\AtsReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The §5.2 report body, exactly as the analyzer builds it (no wrapper, nothing added or reordered). */
class AtsReportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AtsReport $report */
        $report = $this->resource;

        return $report->toArray();
    }
}
