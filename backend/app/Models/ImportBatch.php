<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ImportBatch extends Model {protected $hidden=['backup_path'];protected $fillable=['store_id','user_id','type','original_filename','file_hash','source_start_date','source_end_date','status','rows_read','created_count','updated_count','error_count','summary','backup_path','error_message','rolled_back_at'];protected function casts():array{return['source_start_date'=>'date','source_end_date'=>'date','summary'=>'array','rolled_back_at'=>'datetime'];}public function errors(){return $this->hasMany(ImportError::class);}}
