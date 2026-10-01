<?php

namespace App\Http\Resources;

/** A saved job search. */
class JobSearchResource extends ModelResource
{
    protected const FIELDS = ['id', 'user_id', 'name', 'query', 'country', 'country_name', 'city', 'experience', 'work_mode', 'filters', 'alerts_enabled', 'alert_frequency', 'last_run_at', 'created_at', 'updated_at'];
}
