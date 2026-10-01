<?php

namespace App\Http\Resources;

/** A provider integration for the admin: the secret is never part of the shape. */
class IntegrationResource extends ModelResource
{
    protected const FIELDS = ['id', 'provider', 'type', 'model', 'settings', 'enabled', 'priority', 'tested_at', 'last_error', 'created_at', 'updated_at'];
}
