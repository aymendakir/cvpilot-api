<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * A response shape defined by an explicit field list, so a new column (or a
 * secret) never reaches a client by accident. Values come from the model's
 * normal serialization (casts, ISO dates); wrapping is disabled app-wide.
 */
abstract class ModelResource extends JsonResource
{
    /** @var array<int, string> */
    protected const FIELDS = [];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return Arr::only($this->resource->attributesToArray(), static::FIELDS);
    }

    /** Resolve every item of a paginator, keeping Laravel's paginator JSON keys (`data`, `last_page`, ...). */
    public static function paginate($paginator)
    {
        return $paginator->through(fn ($model) => static::make($model)->resolve());
    }
}
