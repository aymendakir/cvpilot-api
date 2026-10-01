<?php

namespace App\Http\Controllers\Api\V1;

class CsrfTokenController
{
    /** @return array{token: string} */
    public function __invoke(): array
    {
        return ['token' => csrf_token()];
    }
}
