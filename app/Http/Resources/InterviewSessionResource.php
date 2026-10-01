<?php

namespace App\Http\Resources;

/** A mock interview, as its owner sees it. */
class InterviewSessionResource extends ModelResource
{
    protected const FIELDS = ['id', 'user_id', 'job_workspace_id', 'title', 'company', 'cv_text', 'job_description', 'transcript', 'feedback', 'status', 'created_at', 'updated_at'];
}
