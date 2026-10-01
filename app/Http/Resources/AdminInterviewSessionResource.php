<?php

namespace App\Http\Resources;

/** A mock interview in the admin user detail, without the CV text. */
class AdminInterviewSessionResource extends ModelResource
{
    protected const FIELDS = ['id', 'user_id', 'job_workspace_id', 'title', 'company', 'job_description', 'transcript', 'feedback', 'status', 'created_at', 'updated_at'];
}
