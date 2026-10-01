<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class StoreIntegrationRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'provider' => 'required|in:openai,anthropic,gemini,groq,mistral,openrouter,bazaarlink,jsearch,adzuna,jooble,arbeitnow',
            'type' => 'required|in:ai,jobs',
            'secret' => 'nullable|string|max:1000',
            'model' => 'nullable|string|max:120',
            'settings' => 'nullable|array',
            'enabled' => 'required|boolean',
            'priority' => 'nullable|integer|min:1|max:999',
        ];
    }
}
