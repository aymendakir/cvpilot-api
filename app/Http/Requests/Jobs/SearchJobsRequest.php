<?php

namespace App\Http\Requests\Jobs;

use App\Http\Requests\ApiFormRequest;

class SearchJobsRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
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
        ];
    }
}
