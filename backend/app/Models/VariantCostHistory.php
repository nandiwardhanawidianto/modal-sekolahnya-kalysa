<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class VariantCostHistory extends Model {protected $fillable=['product_variant_id','hpp','admin_percent','effective_from','created_by'];protected function casts():array{return['effective_from'=>'date','admin_percent'=>'decimal:4'];}public function variant(){return $this->belongsTo(ProductVariant::class,'product_variant_id');}}
