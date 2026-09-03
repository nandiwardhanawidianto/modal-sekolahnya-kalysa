<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\SoftDeletes;
class Store extends Model {use SoftDeletes;protected $fillable=['name','shopee_username','shopee_shop_id','active'];protected function casts():array{return['active'=>'boolean'];}public function products(){return $this->hasMany(Product::class);}public function variants(){return $this->hasMany(ProductVariant::class);}public function orders(){return $this->hasMany(Order::class);}public function feeHistories(){return $this->hasMany(StoreFeeHistory::class);} }
