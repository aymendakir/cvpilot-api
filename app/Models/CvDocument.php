<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CvDocument extends Model
{
    protected $guarded = [];

    protected $hidden = ['disk_path', 'extracted_text'];

    protected $casts = ['is_primary' => 'boolean', 'expires_at' => 'datetime'];
}
