<?php

namespace App\Http\Resources;

/** An uploaded CV file: never the storage path or the extracted text. */
class CvDocumentResource extends ModelResource
{
    protected const FIELDS = ['id', 'user_id', 'name', 'mime', 'size', 'is_primary', 'created_at', 'updated_at', 'expires_at'];
}
