<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OrderItem extends Model {protected $fillable=['order_id','store_id','product_id','product_variant_id','product_name','variation_name','parent_sku','reference_sku','original_price','unit_price_after_discount','qty','returned_qty','subtotal','line_key','last_import_batch_id'];public function product(){return $this->belongsTo(Product::class);}public function variant(){return $this->belongsTo(ProductVariant::class,'product_variant_id');}public function order(){return $this->belongsTo(Order::class);}}
