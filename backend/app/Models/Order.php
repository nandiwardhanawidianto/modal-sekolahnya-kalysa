<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\SoftDeletes;
class Order extends Model {use SoftDeletes;protected $fillable=['store_id','order_number','status','cancel_reason','return_status','ordered_at','paid_at','completed_at','total_payment','total_qty','returned_qty','product_revenue','last_import_batch_id'];protected function casts():array{return['ordered_at'=>'datetime','paid_at'=>'datetime','completed_at'=>'datetime'];}public function items(){return $this->hasMany(OrderItem::class);}public function settlement(){return $this->hasOne(Settlement::class);}public function adjustments(){return $this->hasMany(Adjustment::class);}public function store(){return $this->belongsTo(Store::class);}}
