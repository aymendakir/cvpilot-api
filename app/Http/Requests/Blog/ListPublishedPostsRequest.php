<?php

namespace App\Http\Requests\Blog;

use App\Http\Requests\ApiFormRequest;

class ListPublishedPostsRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['page' => 'nullable|integer|min:1'];
    }
}
