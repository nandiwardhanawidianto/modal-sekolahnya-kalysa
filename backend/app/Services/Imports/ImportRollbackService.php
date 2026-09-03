<?php
namespace App\Services\Imports;
use App\Models\ActivityLog;
use App\Models\DataCoverageDay;
use App\Models\ImportBatch;
use App\Services\Reports\ClosingStaleService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
final class ImportRollbackService
{
 public function __construct(private ImportBackupService $backups, private ClosingStaleService $closings) {}
 public function rollback(ImportBatch $batch,int $userId): void
 {
  if($batch->rolled_back_at)throw new \RuntimeException('Batch ini sudah di-rollback.');if($batch->status!=='completed')throw new \RuntimeException('Hanya import completed yang bisa di-rollback.');$b=$this->backups->load($batch);DB::transaction(function()use($batch,$b){match($batch->type){'orders'=>$this->orders($batch,$b),'income'=>$this->income($batch,$b),'products'=>$this->products($batch,$b),'ads'=>$this->ads($batch,$b),default=>throw new \RuntimeException('Jenis batch tidak mendukung rollback.')};$batch->update(['status'=>'rolled_back','rolled_back_at'=>now()]);$this->rebuildCoverage($batch->store_id,$batch->type);});$this->closings->all($batch->store_id);$this->backups->remove($batch);ActivityLog::create(['user_id'=>$userId,'store_id'=>$batch->store_id,'action'=>'import.rolled_back','entity_type'=>'import_batch','entity_id'=>(string)$batch->id,'meta'=>['type'=>$batch->type,'file'=>$batch->original_filename]]);
 }
 private function orders(ImportBatch $batch,array $b): void
 {
  $numbers=$b['affected_order_numbers']??[];$current=DB::table('orders')->where('store_id',$batch->store_id)->whereIn('order_number',$numbers)->get();foreach($current as $row)if($row->last_import_batch_id!==null&&(int)$row->last_import_batch_id!==$batch->id)throw new \RuntimeException('Rollback ditolak: ada order yang sudah disentuh import lebih baru.');$ids=$current->pluck('id')->all();if($ids){$newerItems=DB::table('order_items')->whereIn('order_id',$ids)->whereNotNull('last_import_batch_id')->where('last_import_batch_id','!=',$batch->id)->exists();if($newerItems)throw new \RuntimeException('Rollback ditolak: item order sudah disentuh import lebih baru.');DB::table('order_items')->whereIn('order_id',$ids)->delete();}
  $beforeOrders=$b['orders_before']??[];$beforeIds=array_column($beforeOrders,'id');$newIds=array_values(array_diff($ids,$beforeIds));if($newIds)DB::table('orders')->whereIn('id',$newIds)->delete();foreach($beforeOrders as $row){DB::table('orders')->updateOrInsert(['id'=>$row['id']],$row);}foreach($b['items_before']??[] as $row)DB::table('order_items')->updateOrInsert(['id'=>$row['id']],$row);
  foreach($b['settlement_links_before']??[] as $id=>$orderId)DB::table('settlements')->where('id',$id)->update(['order_id'=>$orderId]);foreach($b['adjustment_links_before']??[] as $id=>$orderId)DB::table('adjustments')->where('id',$id)->update(['order_id'=>$orderId]);
 }
 private function income(ImportBatch $batch,array $b): void
 {
  $numbers=$b['affected_settlement_numbers']??[];$fps=$b['affected_adjustment_fingerprints']??[];$q=DB::table('settlements')->where('store_id',$batch->store_id)->whereIn('order_number',$numbers);if((clone$q)->whereNotNull('last_import_batch_id')->where('last_import_batch_id','!=',$batch->id)->exists())throw new \RuntimeException('Rollback ditolak: settlement sudah disentuh import lebih baru.');$q->delete();$a=DB::table('adjustments')->where('store_id',$batch->store_id)->whereIn('fingerprint',$fps);if((clone$a)->whereNotNull('last_import_batch_id')->where('last_import_batch_id','!=',$batch->id)->exists())throw new \RuntimeException('Rollback ditolak: adjustment sudah disentuh import lebih baru.');$a->delete();foreach($b['settlements_before']??[] as $row)DB::table('settlements')->insert($row);foreach($b['adjustments_before']??[] as $row)DB::table('adjustments')->insert($row);
 }
 private function products(ImportBatch $batch,array $b): void
 {
  $variationIds=$b['affected_variation_ids']??[];$current=DB::table('product_variants')->where('store_id',$batch->store_id)->whereIn('shopee_variation_id',$variationIds);if((clone$current)->whereNotNull('last_import_batch_id')->where('last_import_batch_id','!=',$batch->id)->exists())throw new \RuntimeException('Rollback ditolak: master produk sudah disentuh import lebih baru.');$currentRows=$current->get();$currentIds=$currentRows->pluck('id')->all();if($currentIds&&DB::table('variant_cost_histories')->whereIn('product_variant_id',$currentIds)->exists())throw new \RuntimeException('Rollback master produk ditolak: variasi sudah memiliki HPP/admin.');if($currentIds&&DB::table('order_items')->whereIn('product_variant_id',$currentIds)->exists())throw new \RuntimeException('Rollback master produk ditolak: variasi sudah dipakai oleh order.');$beforeVariants=$b['variants_before']??[];$beforeVarIds=array_column($beforeVariants,'id');$newVarIds=array_values(array_diff($currentRows->pluck('id')->all(),$beforeVarIds));if($newVarIds)DB::table('product_variants')->whereIn('id',$newVarIds)->delete();foreach($beforeVariants as $row)DB::table('product_variants')->updateOrInsert(['id'=>$row['id']],$row);
  $productIds=$b['affected_product_ids']??[];$currentProducts=DB::table('products')->where('store_id',$batch->store_id)->whereIn('shopee_product_id',$productIds);if((clone$currentProducts)->whereNotNull('last_import_batch_id')->where('last_import_batch_id','!=',$batch->id)->exists())throw new \RuntimeException('Rollback ditolak: produk sudah disentuh import lebih baru.');$cp=$currentProducts->get();$before=$b['products_before']??[];$beforeIds=array_column($before,'id');$newIds=array_values(array_diff($cp->pluck('id')->all(),$beforeIds));foreach($newIds as $id)if(!DB::table('product_variants')->where('product_id',$id)->exists())DB::table('products')->where('id',$id)->delete();foreach($before as $row)DB::table('products')->updateOrInsert(['id'=>$row['id']],$row);
 }

 private function ads(ImportBatch $batch,array $b): void
 {
  $start=$b['affected_start_date']??$batch->source_start_date?->toDateString();$end=$b['affected_end_date']??$batch->source_end_date?->toDateString();if(!$start||!$end)throw new \RuntimeException('Range backup iklan tidak ditemukan.');
  $current=DB::table('ad_cost_periods')->where('store_id',$batch->store_id)->whereDate('start_date','<=',$end)->whereDate('end_date','>=',$start)->get();
  foreach($current as $row)if($row->last_import_batch_id===null||(int)$row->last_import_batch_id!==$batch->id)throw new \RuntimeException('Rollback iklan ditolak: periode sudah diubah atau ditimpa setelah import ini.');
  if($current->isNotEmpty())DB::table('ad_cost_periods')->whereIn('id',$current->pluck('id')->all())->delete();
  foreach($b['periods_before']??[] as $row)DB::table('ad_cost_periods')->insert($row);
 }
 private function rebuildCoverage(int $storeId,string $type): void
 {
  if(!in_array($type,['orders','income'],true)){DataCoverageDay::where('store_id',$storeId)->where('type',$type)->delete();return;}
  DataCoverageDay::where('store_id',$storeId)->where('type',$type)->delete();
  $batches=ImportBatch::where('store_id',$storeId)->where('type',$type)->where('status','completed')->whereNotNull('source_start_date')->whereNotNull('source_end_date')->orderBy('id')->get();
  foreach($batches as $source){$rows=[];foreach(CarbonPeriod::create(Carbon::parse($source->source_start_date),Carbon::parse($source->source_end_date)) as $date)$rows[]=['store_id'=>$storeId,'type'=>$type,'date'=>$date->toDateString(),'import_batch_id'=>$source->id,'created_at'=>now(),'updated_at'=>now()];foreach(array_chunk($rows,500) as $chunk)DataCoverageDay::upsert($chunk,['store_id','type','date'],['import_batch_id','updated_at']);}
 }

}
