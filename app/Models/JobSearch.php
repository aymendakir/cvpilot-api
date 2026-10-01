<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobSearch extends Model
{
    protected $guarded = [];

    protected $casts = ['filters' => 'array', 'alerts_enabled' => 'boolean', 'last_run_at' => 'datetime'];
}
