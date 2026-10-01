<?php

namespace App\Http\Resources;

/** An upload listed in the admin user detail: metadata only, no CV text. */
class AdminCvDocumentResource extends ModelResource
{
    protected const FIELDS = ['id', 'user_id', 'name', 'mime', 'size', 'is_primary', 'created_at', 'expires_at'];
}
