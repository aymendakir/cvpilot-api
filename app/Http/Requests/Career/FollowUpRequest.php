<?php

namespace App\Http\Requests\Career;

use App\Http\Requests\ApiFormRequest;

class FollowUpRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['type' => 'required|in:follow_up,thank_you,recruiter_message', 'name' => 'nullable|string|max:120', 'title' => 'required|string|max:180', 'company' => 'required|string|max:180', 'context' => 'nullable|string|max:5000', 'tone' => 'nullable|in:professional,warm,confident'];
    }
}
