<?php

namespace App\Http\Resources;

/** A CV template. */
class CvTemplateResource extends ModelResource
{
    protected const FIELDS = ['id', 'name', 'description', 'design', 'sample', 'published', 'created_at', 'updated_at'];
}
