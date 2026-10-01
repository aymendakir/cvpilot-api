<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;

class CurrentUserController
{
    public function __invoke(Request $request): ?User
    {
        return $request->user();
    }
}
