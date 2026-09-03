<?php
namespace App\Services\Reports;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Support\TextKey;
use Carbon\CarbonInterface;
final class CostResolver
{
 public function variant(ProductVariant $variant, CarbonInterface $date): array
 {
  $history=$variant->costHistories->filter(fn($h)=>$h->effective_from->lte($date))->sortByDesc('effective_from')->first();
  return $history?['hpp'=>(int)$history->hpp,'admin_percent'=>$history->admin_percent!==null?(float)$history->admin_percent:null]:['hpp'=>null,'admin_percent'=>null];
 }
 public function item(OrderItem $item, CarbonInterface $date): array
 {
  if($item->variant)return$this->variant($item->variant,$date);
  if(!$item->product)return['hpp'=>null,'admin_percent'=>null];
  $candidates=$item->product->variants;
  $sku=TextKey::normalize($item->reference_sku);
  if($sku!==''){$bySku=$candidates->filter(fn($v)=>TextKey::normalize($v->sku)===$sku);if($bySku->isNotEmpty())$candidates=$bySku;}
  if($candidates->isEmpty())return['hpp'=>null,'admin_percent'=>null];
  $resolved=[];foreach($candidates as $variant)$resolved[]=$this->variant($variant,$date);
  // HPP lama/ambigu hanya boleh diwarisi jika SEMUA kandidat sudah punya HPP dan nilainya identik.
  if(collect($resolved)->contains(fn($x)=>$x['hpp']===null))$hpp=null;else{$hppValues=array_values(array_unique(array_map(fn($x)=>(int)$x['hpp'],$resolved)));$hpp=count($hppValues)===1?$hppValues[0]:null;}
  $adminKeys=array_values(array_unique(array_map(fn($x)=>$x['admin_percent']===null?'null':number_format((float)$x['admin_percent'],4,'.',''),$resolved)));
  $admin=count($adminKeys)===1?$resolved[0]['admin_percent']:null;
  return['hpp'=>$hpp,'admin_percent'=>$admin];
 }
 public function storeFee(Store $store, CarbonInterface $date): array
 {
  $history=$store->feeHistories->filter(fn($h)=>$h->effective_from->lte($date))->sortByDesc('effective_from')->first();
  return $history?['default_admin_percent'=>(float)$history->default_admin_percent,'fixed_fee_per_order'=>(int)$history->fixed_fee_per_order,'configured'=>true]:['default_admin_percent'=>0.0,'fixed_fee_per_order'=>0,'configured'=>false];
 }
}
