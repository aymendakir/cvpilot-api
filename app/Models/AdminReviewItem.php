<?php
namespace App\Models;
class AdminReviewItem extends \Illuminate\Database\Eloquent\Model {
    protected $guarded = [];
    protected $casts = ['payload'=>'encrypted:array','expires_at'=>'datetime'];
    public function user() { return $this->belongsTo(User::class); }
}
