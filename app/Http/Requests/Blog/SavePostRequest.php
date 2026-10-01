<?php

namespace App\Http\Requests\Blog;

use App\Http\Requests\ApiFormRequest;
use App\Models\BlogPost;
use Illuminate\Validation\Rule;

/** Create and update share one shape: the admin form always sends every field. */
class SavePostRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->trimInputs('title', 'slug', 'excerpt', 'author_name');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var BlogPost|null $current */
        $current = $this->route('post');

        return [
            'title' => 'required|string|max:180',
            'slug' => ['required', 'string', 'max:191', 'regex:/^'.BlogPost::SLUG_PATTERN.'$/', Rule::unique('blog_posts', 'slug')->ignore($current?->id)],
            'excerpt' => 'required|string|max:500',
            'body' => 'required|string|min:100|max:100000',
            'author_name' => 'required|string|max:120',
            'status' => ['required', Rule::in(BlogPost::STATUSES)],
        ];
    }
}
