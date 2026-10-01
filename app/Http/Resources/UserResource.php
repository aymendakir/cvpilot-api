<?php

namespace App\Http\Resources;

/** The signed-in member: no session_version, suspended flag or hashes (SPEC §5.8). */
class UserResource extends ModelResource
{
    protected const FIELDS = ['id', 'name', 'email', 'role', 'verified_at', 'country', 'city', 'phone', 'language', 'target_role', 'experience_level', 'preferred_countries', 'work_modes', 'preferences'];
}
