<?php

namespace App\Http\Resources;

/** A saved CV version, as its owner sees it. */
class CvVersionResource extends ModelResource
{
    protected const FIELDS = ['id', 'user_id', 'job_workspace_id', 'name', 'content', 'source', 'created_at', 'updated_at', 'builder_data'];
}
