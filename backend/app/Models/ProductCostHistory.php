<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductCostHistory extends Model
{
    protected $fillable=['product_id','hpp','admin_percent','effective_from','created_by'];

    protected function casts(): array
    {
        return ['effective_from'=>'date','admin_percent'=>'decimal:4'];
    }

    public function product(){return $this->belongsTo(Product::class);}
}
