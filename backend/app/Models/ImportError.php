<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ImportError extends Model {protected $fillable=['import_batch_id','row_number','code','message','raw'];protected function casts():array{return['raw'=>'array'];}}
