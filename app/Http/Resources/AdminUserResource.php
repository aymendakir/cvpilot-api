<?php

namespace App\Http\Resources;

/** A user as an admin sees them in lists and detail. */
class AdminUserResource extends ModelResource
{
    protected const FIELDS = ['id', 'name', 'email', 'role', 'verified_at', 'country', 'city', 'phone', 'language', 'target_role', 'experience_level', 'preferred_countries', 'work_modes', 'preferences', 'suspended', 'last_seen_at', 'created_at'];
}
