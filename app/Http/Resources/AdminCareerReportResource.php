<?php

namespace App\Http\Resources;

/** A generated report in the admin user detail, without its input (which carries the CV text). */
class AdminCareerReportResource extends ModelResource
{
    protected const FIELDS = ['id', 'user_id', 'type', 'output', 'created_at', 'updated_at'];
}
