<?php

namespace App\Http\Requests\Support;

use App\Http\Requests\ApiFormRequest;

class ContactMessageRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:120', 'email' => 'required|email|max:254',
            'topic' => 'required|in:account,technical,privacy,feedback', 'message' => 'required|string|min:20|max:5000',
            'website' => 'nullable|string|max:0',
        ];
    }
}
