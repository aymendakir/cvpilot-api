<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\UserResource;
use Illuminate\Http\Request;

class CurrentUserController
{
    public function __invoke(Request $request): UserResource
    {
        return UserResource::make($request->user());
    }
}
