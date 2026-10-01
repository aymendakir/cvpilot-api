<?php

namespace App\Http\Requests\Account;

use App\Http\Requests\ApiFormRequest;

class DeleteAccountRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['current_password' => 'required|string', 'confirmation' => 'required|in:DELETE'];
    }
}
