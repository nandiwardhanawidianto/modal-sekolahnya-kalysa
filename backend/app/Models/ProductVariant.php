<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\SoftDeletes;
class ProductVariant extends Model {use SoftDeletes;protected $fillable=['product_id','store_id','shopee_variation_id','name','sku','current_price','stock','minimum_purchase','active','last_import_batch_id'];public function product(){return $this->belongsTo(Product::class);}public function costHistories(){return $this->hasMany(VariantCostHistory::class)->orderBy('effective_from');}}
