<?php

namespace App\Http\Resources;

/** A CV version listed in the admin user detail: metadata only, no CV text. */
class AdminCvVersionResource extends ModelResource
{
    protected const FIELDS = ['id', 'user_id', 'job_workspace_id', 'name', 'source', 'created_at', 'updated_at'];
}
