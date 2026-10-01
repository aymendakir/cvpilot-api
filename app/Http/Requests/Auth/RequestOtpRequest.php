<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;

class RequestOtpRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['email' => 'required|email', 'purpose' => 'required|in:verify,reset'];
    }
}
