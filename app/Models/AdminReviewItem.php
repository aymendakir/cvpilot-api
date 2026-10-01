<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminReviewItem extends Model
{
    protected $guarded = [];

    protected $casts = ['payload' => 'encrypted:array', 'expires_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
