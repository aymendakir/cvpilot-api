<?php

namespace App\Http\Resources;

/** A contact message (admin only). */
class SupportMessageResource extends ModelResource
{
    protected const FIELDS = ['id', 'name', 'email', 'topic', 'message', 'status', 'created_at', 'updated_at'];
}
