<?php

namespace App\Http\Resources;

/** A generated report, as its owner sees it. */
class CareerReportResource extends ModelResource
{
    protected const FIELDS = ['id', 'user_id', 'type', 'input', 'output', 'created_at', 'updated_at'];
}
