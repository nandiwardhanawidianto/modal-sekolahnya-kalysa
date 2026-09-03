<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ActivityLog extends Model {protected $fillable=['user_id','store_id','action','entity_type','entity_id','meta'];protected function casts():array{return['meta'=>'array'];}}
