<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class StoreFeeHistory extends Model {protected $fillable=['store_id','default_admin_percent','fixed_fee_per_order','effective_from','created_by'];protected function casts():array{return['effective_from'=>'date','default_admin_percent'=>'decimal:4'];}}
