<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

/** Field-level rules only; provider-specific rules (Gmail, Brevo, Microsoft) stay with the settings logic. */
class SaveSmtpSettingsRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->trimInputs('host', 'username', 'from_address', 'oauth_client_id', 'oauth_tenant');
        if (is_string($this->input('host'))) {
            $this->merge(['host' => strtolower($this->input('host'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'host' => 'required|string|max:253|regex:/^[a-zA-Z0-9.-]+$/', 'port' => 'required|integer|between:1,65535',
            'encryption' => 'required|in:tls,ssl', 'username' => 'nullable|string|max:254', 'password' => 'nullable|string|max:2000',
            'from_address' => 'required|email|max:254', 'from_name' => 'required|string|max:120',
            'auth_mode' => 'sometimes|required|in:password,microsoft',
            'oauth_tenant' => 'nullable|string|max:253|regex:/^[a-zA-Z0-9.-]+$/',
            'oauth_client_id' => 'nullable|uuid', 'oauth_client_secret' => 'nullable|string|max:2000',
        ];
    }
}
