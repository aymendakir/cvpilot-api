<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** SMTP settings for the admin: flags say whether a secret is set, the secrets are never returned. */
class MailSettingResource extends ModelResource
{
    protected const FIELDS = ['host', 'port', 'username', 'encryption', 'from_address', 'from_name', 'auth_mode', 'oauth_tenant', 'oauth_client_id', 'last_error'];

    public function __construct($resource, private readonly string $redirectUri = '')
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $attributes = $this->resource->getAttributes();

        return parent::toArray($request) + [
            'has_password' => ! empty($this->resource->getRawOriginal('password')) || ! empty($attributes['password']),
            'has_oauth_client_secret' => ! empty($attributes['oauth_client_secret']),
            'oauth_connected' => ! empty($attributes['oauth_refresh_token']),
            'oauth_redirect_uri' => $this->redirectUri,
        ];
    }
}
