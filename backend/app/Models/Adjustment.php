<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Adjustment extends Model {protected $fillable=['store_id','order_id','order_number','adjustment_date','type','reason','amount','released_at','fingerprint','last_import_batch_id'];protected function casts():array{return['adjustment_date'=>'date','released_at'=>'date'];}}
