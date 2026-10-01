<?php

namespace App\Http\Requests\Ai;

use App\Http\Requests\ApiFormRequest;

class ChatRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['message' => 'required|string|max:6000', 'provider' => 'nullable|string|max:30', 'context' => 'nullable|string|max:12000'];
    }
}
