<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DataCoverageDay extends Model {protected $fillable=['store_id','type','date','import_batch_id'];protected function casts():array{return['date'=>'date'];}}
