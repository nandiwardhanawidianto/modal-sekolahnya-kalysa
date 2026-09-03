<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Settlement extends Model {protected $fillable=['store_id','order_id','order_number','order_date','released_at','actual_income','product_price','buyer_refund','admin_fee','process_fee','transaction_fee','campaign_fee','seller_voucher','other_fee','last_import_batch_id'];protected function casts():array{return['order_date'=>'date','released_at'=>'date'];}}
