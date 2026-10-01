<?php

namespace App\Http\Resources;

/** A job workspace, as its owner sees it. */
class JobWorkspaceResource extends ModelResource
{
    protected const FIELDS = ['id', 'user_id', 'title', 'company', 'job_url', 'job_description', 'cv_text', 'status', 'created_at', 'updated_at'];
}
