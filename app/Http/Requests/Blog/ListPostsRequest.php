<?php

namespace App\Http\Requests\Blog;

use App\Http\Requests\ApiFormRequest;
use App\Models\BlogPost;
use Illuminate\Validation\Rule;

class ListPostsRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->paginationRules() + ['status' => ['nullable', Rule::in(BlogPost::STATUSES)]];
    }
}
