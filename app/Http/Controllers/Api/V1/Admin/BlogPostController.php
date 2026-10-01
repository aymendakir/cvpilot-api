<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Requests\Blog\ListPostsRequest;
use App\Http\Requests\Blog\SavePostRequest;
use App\Http\Resources\BlogPostResource;
use App\Models\BlogPost;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/** Blog administration. The audit trail is written by the `admin.audit` middleware. */
class BlogPostController
{
    public function index(ListPostsRequest $request)
    {
        $status = $request->validated()['status'] ?? null;

        return BlogPostResource::paginate(
            BlogPost::when($status, fn ($query, $status) => $query->where('status', $status))->orderByDesc('id')->paginate($request->perPage(20))
        );
    }

    public function store(SavePostRequest $request)
    {
        $data = $request->validated();
        $data['published_at'] = $data['status'] === 'published' ? now() : null;

        return BlogPostResource::make($this->save(fn () => BlogPost::create($data)))->response()->setStatusCode(201);
    }

    public function show(BlogPost $post)
    {
        return BlogPostResource::make($post);
    }

    public function update(SavePostRequest $request, BlogPost $post)
    {
        $data = $request->validated();
        // Set the first time the post is published and kept afterwards, even if it goes back to draft.
        if ($data['status'] === 'published' && $post->published_at === null) {
            $data['published_at'] = now();
        }

        return BlogPostResource::make($this->save(function () use ($post, $data) {
            $post->update($data);

            return $post;
        }));
    }

    public function destroy(BlogPost $post)
    {
        $post->delete();

        return response()->noContent();
    }

    /** Two saves racing for one slug: the unique index decides, the answer is the usual 422. */
    private function save(\Closure $write): BlogPost
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['slug' => 'The slug has already been taken.']);
        }
    }
}
