<?php

namespace App\Http\Requests\Career;

use App\Http\Requests\ApiFormRequest;
use App\Services\OutputLanguage;

class PortfolioReviewRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['cv_text' => 'required|string|min:30|max:30000', 'job_description' => 'required|string|min:60|max:30000', 'github_url' => 'nullable|url:https|max:2000', 'portfolio_url' => 'nullable|url:https|max:2000', 'projects' => 'nullable|string|max:15000', 'language' => OutputLanguage::RULE];
    }
}
