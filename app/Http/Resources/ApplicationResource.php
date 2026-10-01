<?php

namespace App\Http\Resources;

/** A tracked job application. */
class ApplicationResource extends ModelResource
{
    protected const FIELDS = ['id', 'user_id', 'external_job_id', 'title', 'company', 'url', 'status', 'match_score', 'notes', 'applied_at', 'created_at', 'updated_at', 'cv_version_id', 'salary', 'application_date', 'reminder_at', 'follow_up_at', 'job_description'];
}
