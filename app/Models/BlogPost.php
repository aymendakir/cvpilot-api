<?php
namespace App\Models;
class BlogPost extends \Illuminate\Database\Eloquent\Model {
 protected $fillable=['title','slug','excerpt','body','author_name','status','published_at'];
 protected $casts=['published_at'=>'datetime'];
 public function scopePublished($query) { return $query->where('status','published')->whereNotNull('published_at')->where('published_at','<=',now()); }
}
