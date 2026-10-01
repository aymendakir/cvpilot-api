<?php

namespace App\Http\Requests\Applications;

use App\Http\Requests\ApiFormRequest;

class StoreApplicationRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['cv_version_id' => 'nullable|integer', 'external_job_id' => 'nullable|string|max:255', 'title' => 'required|string|max:255', 'company' => 'required|string|max:255', 'url' => 'required|url:https|max:2000', 'job_description' => 'nullable|string|max:30000', 'status' => 'nullable|in:saved,prepared,applied,interview,rejected,offer', 'match_score' => 'nullable|integer|min:0|max:100', 'salary' => 'nullable|string|max:120', 'application_date' => 'nullable|date', 'reminder_at' => 'nullable|date', 'follow_up_at' => 'nullable|date', 'notes' => 'nullable|string|max:5000'];
    }
}
