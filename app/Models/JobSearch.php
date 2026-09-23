<?php
namespace App\Models;
class JobSearch extends \Illuminate\Database\Eloquent\Model{protected $guarded=[];protected $casts=['filters'=>'array','alerts_enabled'=>'boolean','last_run_at'=>'datetime'];}
