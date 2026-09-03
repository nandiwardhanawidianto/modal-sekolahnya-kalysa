<?php
namespace App\Services\Imports;
use App\Models\Adjustment;
use App\Models\Order;
use App\Models\Settlement;
use App\Models\Store;
use App\Services\Reports\ClosingStaleService;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class IncomeImporter
{
 public function __construct(private ImportSupport $support, private ClosingStaleService $closings, private ImportBackupService $backups) {}
 public function import(Store $store, UploadedFile $file, int $userId): array
 {
  $batch=$this->support->begin($store->id,$userId,'income',$file);
  try{
   $reader=new XlsxReader($file->getRealPath());
   $username=$this->summaryValue($reader,'Username (Penjual)');$start=$this->summaryValue($reader,'Dari');$end=$this->summaryValue($reader,'ke');
   if($username){if($store->shopee_username && strcasecmp($store->shopee_username,$username)!==0)throw new \RuntimeException("File penghasilan milik {$username}, bukan {$store->shopee_username}.");if(!$store->shopee_username)$store->update(['shopee_username'=>$username]);}
   $settlementRows=[];$rowsRead=0;
   foreach($reader->sheetNames() as $sheet){if(!str_starts_with($sheet,'Penghasilan -'))continue;$header=$reader->findHeader($sheet,['Lihat berdasarkan','No. Pesanan','Waktu Pesanan Dibuat','Tanggal Dana Dilepaskan','Total Penghasilan','Harga Produk','Jumlah Pengembalian Dana ke Pembeli','Biaya Administrasi','Biaya Proses Pesanan','Biaya Transaksi','Biaya Kampanye','Voucher disponsor oleh Penjual']);$h=$header['map'];
    foreach($reader->rows($sheet) as $row){if($row['row']<=$header['row'])continue;$v=$row['values'];if(strcasecmp(trim($v[$h['Lihat berdasarkan']]??''),'Order')!==0)continue;$orderNo=trim($v[$h['No. Pesanan']]??'');if($orderNo==='')continue;$rowsRead++;
     $settlementRows[$orderNo]=['store_id'=>$store->id,'order_number'=>$orderNo,'order_date'=>$this->date($v[$h['Waktu Pesanan Dibuat']]??null),'released_at'=>$this->date($v[$h['Tanggal Dana Dilepaskan']]??null),'actual_income'=>Money::rupiah($v[$h['Total Penghasilan']]??0),'product_price'=>Money::rupiah($v[$h['Harga Produk']]??0),'buyer_refund'=>Money::rupiah($v[$h['Jumlah Pengembalian Dana ke Pembeli']]??0),'admin_fee'=>Money::rupiah($v[$h['Biaya Administrasi']]??0),'process_fee'=>Money::rupiah($v[$h['Biaya Proses Pesanan']]??0),'transaction_fee'=>Money::rupiah($v[$h['Biaya Transaksi']]??0),'campaign_fee'=>Money::rupiah($v[$h['Biaya Kampanye']]??0),'seller_voucher'=>Money::rupiah($v[$h['Voucher disponsor oleh Penjual']]??0),'other_fee'=>$this->sumOptional($v,$h,['Biaya Komisi AMS','Biaya Isi Saldo Otomatis (dari Penghasilan)','Premi','FBS Fee','PPh 22']),'last_import_batch_id'=>$batch->id,'created_at'=>now(),'updated_at'=>now()];
    }
   }
   $adjustRows=$this->adjustments($reader,$store,$batch->id);
   $beforeSettlements=Settlement::where('store_id',$store->id)->whereIn('order_number',array_keys($settlementRows))->get();$fps=array_column($adjustRows,'fingerprint');$beforeAdjustments=$fps?Adjustment::where('store_id',$store->id)->whereIn('fingerprint',$fps)->get():collect();$this->backups->save($batch,['affected_settlement_numbers'=>array_keys($settlementRows),'affected_adjustment_fingerprints'=>$fps,'settlements_before'=>$this->backups->attrs($beforeSettlements),'adjustments_before'=>$this->backups->attrs($beforeAdjustments)]);
   $existing=Settlement::where('store_id',$store->id)->whereIn('order_number',array_keys($settlementRows))->pluck('order_number')->all();$existingSet=array_fill_keys($existing,true);$created=0;$updated=0;foreach(array_keys($settlementRows) as $n){isset($existingSet[$n])?$updated++:$created++;}
   DB::transaction(function() use($settlementRows,$adjustRows,$store){
    $orderMap=Order::where('store_id',$store->id)->whereIn('order_number',array_keys($settlementRows))->pluck('id','order_number')->all();$rows=[];foreach($settlementRows as $n=>$r){$r['order_id']=$orderMap[$n]??null;$rows[]=$r;}foreach(array_chunk($rows,500) as $chunk)DB::table('settlements')->upsert($chunk,['store_id','order_number'],['order_id','order_date','released_at','actual_income','product_price','buyer_refund','admin_fee','process_fee','transaction_fee','campaign_fee','seller_voucher','other_fee','last_import_batch_id','updated_at']);
    if($adjustRows){$allNos=array_values(array_unique(array_filter(array_column($adjustRows,'order_number'))));$orderMap2=Order::where('store_id',$store->id)->whereIn('order_number',$allNos)->pluck('id','order_number')->all();foreach($adjustRows as &$r)$r['order_id']=$r['order_number']?($orderMap2[$r['order_number']]??null):null;unset($r);foreach(array_chunk($adjustRows,500) as $chunk)DB::table('adjustments')->upsert($chunk,['store_id','fingerprint'],['order_id','order_number','adjustment_date','type','reason','amount','released_at','last_import_batch_id','updated_at']);}
   });
   $this->support->coverage($batch,$start,$end);$this->closings->all($store->id);$batch->update(['rows_read'=>$rowsRead+count($adjustRows),'created_count'=>$created,'updated_count'=>$updated]);
   $summary=['seller_username'=>$username,'settlements'=>count($settlementRows),'adjustments'=>count($adjustRows),'created'=>$created,'updated'=>$updated,'period'=>[$start,$end]];$this->support->finish($batch,$summary);return['batch'=>$batch->fresh(),'summary'=>$summary];
  }catch(\Throwable $e){$this->support->fail($batch,$e);throw $e;}
 }
 private function adjustments(XlsxReader $reader,Store $store,int $batchId): array
 {
  if(!in_array('Adjustment',$reader->sheetNames(),true))return[];$header=$reader->findHeader('Adjustment',['Tanggal Penyesuaian Dibuat','Tipe Penyesuaian | Deskripsi','Alasan Penyesuaian','Biaya Penyesuaian','No. Pesanan Terhubung','Tanggal Dana Dilepaskan']);$h=$header['map'];$out=[];
  foreach($reader->rows('Adjustment') as $row){if($row['row']<=$header['row'])continue;$v=$row['values'];$date=$this->date($v[$h['Tanggal Penyesuaian Dibuat']]??null);$type=trim($v[$h['Tipe Penyesuaian | Deskripsi']]??'');$amount=Money::rupiah($v[$h['Biaya Penyesuaian']]??0);if(!$date||$type==='')continue;$orderNo=trim($v[$h['No. Pesanan Terhubung']]??'')?:null;$reason=trim($v[$h['Alasan Penyesuaian']]??'')?:null;$released=$this->date($v[$h['Tanggal Dana Dilepaskan']]??null);$fingerprint=hash('sha256',implode('|',[$date,$type,(string)$amount,$orderNo,$reason]));$out[]=['store_id'=>$store->id,'order_id'=>null,'order_number'=>$orderNo,'adjustment_date'=>$date,'type'=>$type,'reason'=>$reason,'amount'=>$amount,'released_at'=>$released,'fingerprint'=>$fingerprint,'last_import_batch_id'=>$batchId,'created_at'=>now(),'updated_at'=>now()];}
  return$out;
 }
 private function summaryValue(XlsxReader $reader,string $label): ?string
 {
  if(!in_array('Summary',$reader->sheetNames(),true))return null;foreach($reader->rows('Summary') as $row){$vals=$row['values'];foreach($vals as $i=>$value){if(trim($value)===$label){$next=trim($vals[$i+1]??'');return$next!==''?$next:null;}}if($row['row']>20)break;}return null;
 }
 private function sumOptional(array $values,array $headers,array $names): int {$sum=0;foreach($names as $n)if(isset($headers[$n]))$sum+=Money::rupiah($values[$headers[$n]]??0);return$sum;}
 private function date(mixed $value): ?string {if(!$value||$value==='-')return null;try{return Carbon::parse((string)$value,'Asia/Jakarta')->toDateString();}catch(\Throwable){return null;}}
}
