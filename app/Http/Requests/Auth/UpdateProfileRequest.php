<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;

class UpdateProfileRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['name' => 'sometimes|required|string|max:120', 'phone' => 'nullable|string|max:40', 'country' => 'nullable|string|size:2', 'city' => 'nullable|string|max:120', 'language' => 'sometimes|in:en,fr,es,ar', 'target_role' => 'nullable|string|max:120', 'experience_level' => 'nullable|string|max:80', 'preferred_countries' => 'nullable|array|max:20', 'preferred_countries.*' => 'string|max:80', 'work_modes' => 'nullable|array|max:5', 'work_modes.*' => 'string|max:30', 'preferences' => 'nullable|array'];
    }
}
