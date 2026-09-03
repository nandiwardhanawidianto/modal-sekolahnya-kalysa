<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class MonthlyClosing extends Model {protected $fillable=['store_id','month','snapshot','is_stale','closed_by','closed_at'];protected function casts():array{return['month'=>'date','snapshot'=>'array','is_stale'=>'boolean','closed_at'=>'datetime'];}}
