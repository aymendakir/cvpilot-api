<?php

namespace App\Http\Resources;

/** The short shape returned right after saving an integration. */
class IntegrationSummaryResource extends ModelResource
{
    protected const FIELDS = ['id', 'provider', 'type', 'model', 'enabled', 'priority', 'updated_at'];
}
