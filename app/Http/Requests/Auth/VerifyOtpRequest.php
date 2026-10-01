<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;

class VerifyOtpRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['email' => 'required|email', 'purpose' => 'required|in:verify,reset', 'code' => 'required|digits:6', 'password' => 'required_if:purpose,reset|nullable|string|min:12|max:128|confirmed'];
    }
}
