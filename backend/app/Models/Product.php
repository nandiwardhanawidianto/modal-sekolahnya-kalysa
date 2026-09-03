<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;
    protected $fillable=['store_id','shopee_product_id','name','parent_sku','active','last_import_batch_id'];
    public function variants(){return $this->hasMany(ProductVariant::class);}
    public function costHistories(){return $this->hasMany(ProductCostHistory::class)->orderBy('effective_from');}
    public function store(){return $this->belongsTo(Store::class);}
}
