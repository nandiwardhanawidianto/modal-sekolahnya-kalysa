<?php
namespace App\Services\Imports;
use App\Models\Adjustment;
use App\Models\ImportError;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Settlement;
use App\Models\Store;
use App\Services\Reports\ClosingStaleService;
use App\Support\Money;
use App\Support\TextKey;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class OrderImporter
{
 public function __construct(private ImportSupport $support, private ClosingStaleService $closings, private ImportBackupService $backups) {}
 public function import(Store $store, UploadedFile $file, int $userId): array
 {
  $batch=$this->support->begin($store->id,$userId,'orders',$file);
  try {
   $reader=new XlsxReader($file->getRealPath());$sheet=in_array('orders',$reader->sheetNames(),true)?'orders':($reader->sheetNames()[0]??null);if(!$sheet)throw new \RuntimeException('Workbook order kosong.');
   $header=$reader->findHeader($sheet,['No. Pesanan','Status Pesanan','Alasan Pembatalan','Status Pembatalan/ Pengembalian','Waktu Pesanan Dibuat','Waktu Pembayaran Dilakukan','SKU Induk','Nama Produk','Nomor Referensi SKU','Nama Variasi','Harga Awal','Harga Setelah Diskon','Jumlah','Returned quantity','Subtotal Pesanan','Total Pembayaran','Waktu Pesanan Selesai']);$h=$header['map'];
   [$productNameMap,$parentSkuProductMap,$variationNameMap,$variationCanonicalMap,$productSkuMap,$globalVariationNameMap,$globalVariationCanonicalMap,$globalSkuMap,$variantProductMap]=$this->variantMaps($store->id);$orders=[];$items=[];$unmapped=[];$rowsRead=0;$minDate=null;$maxDate=null;
   foreach($reader->rows($sheet) as $row){if($row['row']<=$header['row'])continue;$v=$row['values'];$orderNo=trim($v[$h['No. Pesanan']]??'');if($orderNo==='')continue;$rowsRead++;
    $orderedAt=$this->dateTime($v[$h['Waktu Pesanan Dibuat']]??null);if($orderedAt){$d=$orderedAt->toDateString();$minDate=$minDate===null||$d<$minDate?$d:$minDate;$maxDate=$maxDate===null||$d>$maxDate?$d:$maxDate;}
    $qty=(int)($v[$h['Jumlah']]??0);$returned=(int)($v[$h['Returned quantity']]??0);$subtotal=Money::rupiah($v[$h['Subtotal Pesanan']]??0);
    if(!isset($orders[$orderNo]))$orders[$orderNo]=['store_id'=>$store->id,'order_number'=>$orderNo,'status'=>trim($v[$h['Status Pesanan']]??''),'cancel_reason'=>trim($v[$h['Alasan Pembatalan']]??'')?:null,'return_status'=>trim($v[$h['Status Pembatalan/ Pengembalian']]??'')?:null,'ordered_at'=>$orderedAt?->format('Y-m-d H:i:s'),'paid_at'=>$this->dateTime($v[$h['Waktu Pembayaran Dilakukan']]??null)?->format('Y-m-d H:i:s'),'completed_at'=>$this->dateTime($v[$h['Waktu Pesanan Selesai']]??null)?->format('Y-m-d H:i:s'),'total_payment'=>Money::rupiah($v[$h['Total Pembayaran']]??0),'total_qty'=>0,'returned_qty'=>0,'product_revenue'=>0,'last_import_batch_id'=>$batch->id,'created_at'=>now(),'updated_at'=>now()];
    $orders[$orderNo]['total_qty']+=$qty;$orders[$orderNo]['returned_qty']+=$returned;$orders[$orderNo]['product_revenue']+=$subtotal;
    $productName=trim($v[$h['Nama Produk']]??'');$variationName=trim($v[$h['Nama Variasi']]??'');$refSku=trim($v[$h['Nomor Referensi SKU']]??'');$parentSku=trim($v[$h['SKU Induk']]??'');$productId=null;if(TextKey::normalize($parentSku)!=='')$productId=$parentSkuProductMap[TextKey::normalize($parentSku)]??null;if(!$productId)$productId=$productNameMap[TextKey::normalize($productName)]??null;$variantId=$this->matchVariant($productId,$productName,$variationName,$refSku,$variationNameMap,$variationCanonicalMap,$productSkuMap,$globalVariationNameMap,$globalVariationCanonicalMap,$globalSkuMap);if(!$productId&&$variantId)$productId=$variantProductMap[$variantId]??null;
    if(!$variantId)$unmapped[TextKey::normalize($productName).'|'.TextKey::normalize($variationName)]=['product'=>$productName,'variation'=>$variationName,'sku'=>$refSku];
    $unit=Money::rupiah($v[$h['Harga Setelah Diskon']]??0);$lineKey=TextKey::line($productName,$variationName,$refSku,$unit);
    $itemKey=$orderNo.'|'.$lineKey;
    if(isset($items[$itemKey])){$items[$itemKey]['qty']+=$qty;$items[$itemKey]['returned_qty']+=$returned;$items[$itemKey]['subtotal']+=$subtotal;$items[$itemKey]['updated_at']=now();}
    else $items[$itemKey]=['order_number'=>$orderNo,'store_id'=>$store->id,'product_id'=>$productId,'product_variant_id'=>$variantId,'product_name'=>$productName,'variation_name'=>$variationName?:null,'parent_sku'=>$parentSku?:null,'reference_sku'=>$refSku?:null,'original_price'=>Money::rupiah($v[$h['Harga Awal']]??0),'unit_price_after_discount'=>$unit,'qty'=>$qty,'returned_qty'=>$returned,'subtotal'=>$subtotal,'line_key'=>$lineKey,'last_import_batch_id'=>$batch->id,'created_at'=>now(),'updated_at'=>now()];
   }
   $beforeOrders=Order::where('store_id',$store->id)->whereIn('order_number',array_keys($orders))->get();$beforeOrderIds=$beforeOrders->pluck('id')->all();$beforeItems=$beforeOrderIds?\App\Models\OrderItem::whereIn('order_id',$beforeOrderIds)->get():collect();$beforeSettlements=Settlement::where('store_id',$store->id)->whereIn('order_number',array_keys($orders))->get();$beforeAdjustments=Adjustment::where('store_id',$store->id)->whereIn('order_number',array_keys($orders))->get();$this->backups->save($batch,['affected_order_numbers'=>array_keys($orders),'orders_before'=>$this->backups->attrs($beforeOrders),'items_before'=>$this->backups->attrs($beforeItems),'settlement_links_before'=>$beforeSettlements->mapWithKeys(fn($x)=>[(string)$x->id=>$x->order_id])->all(),'adjustment_links_before'=>$beforeAdjustments->mapWithKeys(fn($x)=>[(string)$x->id=>$x->order_id])->all()]);
   $existing=Order::where('store_id',$store->id)->whereIn('order_number',array_keys($orders))->pluck('order_number')->all();$existingSet=array_fill_keys($existing,true);$created=0;$updated=0;foreach(array_keys($orders) as $n){isset($existingSet[$n])?$updated++:$created++;}
   DB::transaction(function() use($orders,$items,$store,$batch){
    foreach(array_chunk(array_values($orders),500) as $chunk) DB::table('orders')->upsert($chunk,['store_id','order_number'],['status','cancel_reason','return_status','ordered_at','paid_at','completed_at','total_payment','total_qty','returned_qty','product_revenue','last_import_batch_id','updated_at']);
    $idMap=Order::where('store_id',$store->id)->whereIn('order_number',array_keys($orders))->pluck('id','order_number')->all();$itemRows=[];
    foreach($items as $item){$item['order_id']=$idMap[$item['order_number']];unset($item['order_number']);$itemRows[]=$item;}
    foreach(array_chunk($itemRows,500) as $chunk) DB::table('order_items')->upsert($chunk,['order_id','line_key'],['product_id','product_variant_id','product_name','variation_name','parent_sku','reference_sku','original_price','unit_price_after_discount','qty','returned_qty','subtotal','last_import_batch_id','updated_at']);
    // Export order dianggap snapshot lengkap untuk setiap No. Pesanan yang ada di file.
    // Hapus baris lama yang tidak muncul lagi agar re-import overlap tidak meninggalkan HPP phantom.
    DB::table('order_items')->whereIn('order_id',array_values($idMap))->where(function($q) use($batch){$q->whereNull('last_import_batch_id')->orWhere('last_import_batch_id','!=',$batch->id);})->delete();
    $settlements=Settlement::where('store_id',$store->id)->whereIn('order_number',array_keys($idMap))->get();foreach($settlements as $s){$s->order_id=$idMap[$s->order_number]??null;$s->save();}
    $adjustments=Adjustment::where('store_id',$store->id)->whereIn('order_number',array_keys($idMap))->get();foreach($adjustments as $a){$a->order_id=$idMap[$a->order_number]??null;$a->save();}
   });
   foreach($unmapped as $raw) ImportError::create(['import_batch_id'=>$batch->id,'code'=>'UNMAPPED_VARIANT','message'=>'Produk/variasi belum dapat dipetakan ke master. HPP tidak akan dianggap 0.','raw'=>$raw]);
   [$fileStart,$fileEnd]=ImportSupport::dateRangeFromFilename($file->getClientOriginalName());$coverageStart=$fileStart?:$minDate;$coverageEnd=$fileEnd?:$maxDate;$this->support->coverage($batch,$coverageStart,$coverageEnd);$this->closings->range($store->id,$coverageStart,$coverageEnd);
   $batch->update(['rows_read'=>$rowsRead,'created_count'=>$created,'updated_count'=>$updated,'error_count'=>count($unmapped)]);
   $summary=['orders'=>count($orders),'items'=>count($items),'created'=>$created,'updated'=>$updated,'unmapped_variants'=>count($unmapped),'status'=>array_count_values(array_map(fn($o)=>$o['status'],$orders))];
   $this->support->finish($batch,$summary);return ['batch'=>$batch->fresh(),'summary'=>$summary];
  }catch(\Throwable $e){$this->support->fail($batch,$e);throw $e;}
 }
 private function variantMaps(int $storeId): array
 {
  $products=Product::where('store_id',$storeId)->get();$productNameCandidates=[];$parentSkuCandidates=[];$productNames=[];
  foreach($products as $p){$productNames[$p->id]=$p->name;$productNameCandidates[TextKey::normalize($p->name)][]=$p->id;if(TextKey::normalize($p->parent_sku)!=='')$parentSkuCandidates[TextKey::normalize($p->parent_sku)][]=$p->id;}
  $unique=fn(array $c)=>array_filter(array_map(fn($ids)=>count(array_unique($ids))===1?array_values(array_unique($ids))[0]:null,$c),fn($v)=>$v!==null);
  $productNameMap=$unique($productNameCandidates);$parentSkuProductMap=$unique($parentSkuCandidates);
  $variants=ProductVariant::where('store_id',$storeId)->get();$nameCandidates=[];$canonicalCandidates=[];$productSkuCandidates=[];$globalName=[];$globalCanonical=[];$globalSku=[];$variantProductMap=[];
  foreach($variants as $v){$variantProductMap[$v->id]=$v->product_id;$nameCandidates[$v->product_id.'|'.TextKey::normalize($v->name)][]=$v->id;$canonicalCandidates[$v->product_id.'|'.TextKey::canonicalVariation($v->name)][]=$v->id;$pk=TextKey::normalize($productNames[$v->product_id]??'');$globalName[$pk.'|'.TextKey::normalize($v->name)][]=$v->id;$globalCanonical[$pk.'|'.TextKey::canonicalVariation($v->name)][]=$v->id;if($v->sku){$productSkuCandidates[$v->product_id.'|'.TextKey::normalize($v->sku)][]=$v->id;$globalSku[TextKey::normalize($v->sku)][]=$v->id;}}
  return[$productNameMap,$parentSkuProductMap,$unique($nameCandidates),$unique($canonicalCandidates),$unique($productSkuCandidates),$unique($globalName),$unique($globalCanonical),$unique($globalSku),$variantProductMap];
 }
 private function matchVariant(?int $productId,string $productName,string $variation,string $sku,array $nameMap,array $canonicalMap,array $productSkuMap,array $globalNameMap,array $globalCanonicalMap,array $globalSkuMap): ?int
 {
  if($productId){$nameKey=$productId.'|'.TextKey::normalize($variation);if(isset($nameMap[$nameKey]))return$nameMap[$nameKey];$canonical=$productId.'|'.TextKey::canonicalVariation($variation);if(isset($canonicalMap[$canonical]))return$canonicalMap[$canonical];if(TextKey::normalize($sku)!==''){if(isset($productSkuMap[$productId.'|'.TextKey::normalize($sku)]))return$productSkuMap[$productId.'|'.TextKey::normalize($sku)];}}
  $pk=TextKey::normalize($productName);$gk=$pk.'|'.TextKey::normalize($variation);if(isset($globalNameMap[$gk]))return$globalNameMap[$gk];$gc=$pk.'|'.TextKey::canonicalVariation($variation);if(isset($globalCanonicalMap[$gc]))return$globalCanonicalMap[$gc];if(TextKey::normalize($sku)!=='')return$globalSkuMap[TextKey::normalize($sku)]??null;return null;
 }
 private function dateTime(mixed $value): ?Carbon {if(!$value||$value==='-')return null;try{return Carbon::parse((string)$value,'Asia/Jakarta');}catch(\Throwable){return null;}}
}
