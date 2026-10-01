<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** An application in the admin list, with the owner's name and email. */
class AdminApplicationResource extends ApplicationResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $this->resource->user;

        return parent::toArray($request) + ['user' => $user ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email] : null];
    }
}
