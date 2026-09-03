<?php
namespace App\Services\Imports;
use App\Models\ActivityLog;
use App\Models\DataCoverageDay;
use App\Models\ImportBatch;
use App\Models\ImportError;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\UploadedFile;

final class ImportSupport
{
 public function begin(int $storeId,int $userId,string $type,UploadedFile $file): ImportBatch
 {
  return ImportBatch::create(['store_id'=>$storeId,'user_id'=>$userId,'type'=>$type,'original_filename'=>$file->getClientOriginalName(),'file_hash'=>hash_file('sha256',$file->getRealPath()),'status'=>'processing']);
 }
 public function error(ImportBatch $batch,?int $row,string $message,array $raw=[],?string $code=null): void
 {
  ImportError::create(['import_batch_id'=>$batch->id,'row_number'=>$row,'code'=>$code,'message'=>$message,'raw'=>$raw ?: null]);
  $batch->increment('error_count');
 }
 public function coverage(ImportBatch $batch,?string $start,?string $end): void
 {
  if (!$start || !$end) return;
  $batch->update(['source_start_date'=>$start,'source_end_date'=>$end]);
  $rows=[]; foreach(CarbonPeriod::create(Carbon::parse($start),Carbon::parse($end)) as $date) $rows[]=['store_id'=>$batch->store_id,'type'=>$batch->type,'date'=>$date->toDateString(),'import_batch_id'=>$batch->id,'created_at'=>now(),'updated_at'=>now()];
  foreach(array_chunk($rows,500) as $chunk) DataCoverageDay::upsert($chunk,['store_id','type','date'],['import_batch_id','updated_at']);
 }
 public function finish(ImportBatch $batch,array $summary=[]): void
 {
  $batch->update(['status'=>'completed','summary'=>$summary]);
  ActivityLog::create(['user_id'=>$batch->user_id,'store_id'=>$batch->store_id,'action'=>'import.completed','entity_type'=>'import_batch','entity_id'=>(string)$batch->id,'meta'=>['type'=>$batch->type,'file'=>$batch->original_filename,'summary'=>$summary]]);
 }
 public function fail(ImportBatch $batch,\Throwable $e): void
 {
  $batch->update(['status'=>'failed','error_message'=>$e->getMessage()]);
 }
 public static function dateRangeFromFilename(string $filename): array
 {
  if (preg_match('/(20\d{6})[_-](20\d{6})/',$filename,$m)) return [Carbon::createFromFormat('Ymd',$m[1])->toDateString(),Carbon::createFromFormat('Ymd',$m[2])->toDateString()];
  return [null,null];
 }
}
