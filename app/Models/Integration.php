<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Integration extends Model
{
    protected $guarded = [];

    protected $hidden = ['secret'];

    protected $casts = ['secret' => 'encrypted', 'settings' => 'array', 'enabled' => 'boolean', 'priority' => 'integer', 'tested_at' => 'datetime'];
}
