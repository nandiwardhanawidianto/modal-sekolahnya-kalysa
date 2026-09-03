<?php
namespace App\Services\Imports;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class ProductImporter
{
 public function __construct(private ImportSupport $support, private ImportBackupService $backups, private OrderItemRemapper $remapper) {}
 public function import(Store $store, UploadedFile $file, int $userId): array
 {
  $batch=$this->support->begin($store->id,$userId,'products',$file);
  try {
   $reader=new XlsxReader($file->getRealPath());$sheet=$reader->sheetNames()[0]??null;if(!$sheet)throw new \RuntimeException('Workbook kosong.');
   $header=$reader->findHeader($sheet,['Kode Produk','Nama Produk','Kode Variasi','Nama Variasi','Harga']);$h=$header['map'];
   $created=0;$updated=0;$read=0;$affectedProductIds=[];$affectedVariationIds=[];foreach($reader->rows($sheet) as $pre){if($pre['row']<=$header['row'])continue;$pv=$pre['values'];$pid=trim($pv[$h['Kode Produk']]??'');$vid=trim($pv[$h['Kode Variasi']]??'');if($pid!==''&&$vid!==''&&ctype_digit($pid)){$affectedProductIds[$pid]=true;$affectedVariationIds[$vid]=true;}}$productsBefore=Product::withTrashed()->where('store_id',$store->id)->whereIn('shopee_product_id',array_keys($affectedProductIds))->get();$variantsBefore=ProductVariant::withTrashed()->where('store_id',$store->id)->whereIn('shopee_variation_id',array_keys($affectedVariationIds))->get();$this->backups->save($batch,['affected_product_ids'=>array_keys($affectedProductIds),'affected_variation_ids'=>array_keys($affectedVariationIds),'products_before'=>$this->backups->attrs($productsBefore),'variants_before'=>$this->backups->attrs($variantsBefore)]);
   DB::transaction(function() use($reader,$sheet,$header,$h,$store,$batch,&$created,&$updated,&$read){
    foreach($reader->rows($sheet) as $row){if($row['row']<=$header['row'])continue;$v=$row['values'];$productId=trim($v[$h['Kode Produk']]??'');$variationId=trim($v[$h['Kode Variasi']]??'');if($productId===''||$variationId===''||!ctype_digit($productId))continue;$read++;
     $product=Product::withTrashed()->where('store_id',$store->id)->where('shopee_product_id',$productId)->first();$isNew=!$product;
     if(!$product)$product=new Product(['store_id'=>$store->id,'shopee_product_id'=>$productId]);
     $product->fill(['name'=>trim($v[$h['Nama Produk']]??''),'parent_sku'=>trim($v[$h['SKU Induk']]??'')?:null,'active'=>true,'last_import_batch_id'=>$batch->id]);$product->deleted_at=null;$product->save();
     $variant=ProductVariant::withTrashed()->where('store_id',$store->id)->where('shopee_variation_id',$variationId)->first();$variantNew=!$variant;
     if(!$variant)$variant=new ProductVariant(['store_id'=>$store->id,'shopee_variation_id'=>$variationId]);
     $variant->fill(['product_id'=>$product->id,'name'=>trim($v[$h['Nama Variasi']]??'')?:null,'sku'=>trim($v[$h['SKU']]??'')?:null,'current_price'=>Money::rupiah($v[$h['Harga']]??0),'stock'=>isset($h['Stok'])?Money::rupiah($v[$h['Stok']]??0):null,'minimum_purchase'=>isset($h['Min. Jumlah Pembelian']) ? (((int)($v[$h['Min. Jumlah Pembelian']]??0)) ?: null) : null,'active'=>true,'last_import_batch_id'=>$batch->id]);$variant->deleted_at=null;$variant->save();
     if($variantNew)$created++;else $updated++;
    }
   });
   $batch->update(['rows_read'=>$read,'created_count'=>$created,'updated_count'=>$updated]);$remap=$this->remapper->remap($store->id);
   $summary=['variants'=>$read,'created'=>$created,'updated'=>$updated,'products'=>Product::where('store_id',$store->id)->count(),'missing_hpp'=>ProductVariant::where('store_id',$store->id)->whereDoesntHave('costHistories')->count(),'remapped_order_items'=>$remap];
   $this->support->finish($batch,$summary);return ['batch'=>$batch->fresh(),'summary'=>$summary];
  } catch(\Throwable $e){$this->support->fail($batch,$e);throw $e;}
 }
}
