<?php

namespace App\Http\Requests\Applications;

use App\Http\Requests\ApiFormRequest;

class ListApplicationsRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->paginationRules();
    }
}
