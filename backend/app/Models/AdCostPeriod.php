<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdCostPeriod extends Model
{
    protected $fillable = [
        'store_id','start_date','end_date','amount','source','source_filename','source_hash',
        'shopee_username','shopee_shop_id','breakdown','note','updated_by','last_import_batch_id',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'breakdown' => 'array',
        ];
    }
}
