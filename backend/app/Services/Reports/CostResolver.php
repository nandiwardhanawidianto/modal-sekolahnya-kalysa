<?php
namespace App\Services\Reports;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Support\TextKey;
use Carbon\CarbonInterface;

final class CostResolver
{
    public function product(Product $product, CarbonInterface $date): array
    {
        $product->loadMissing('costHistories');
        $history=$product->costHistories->filter(fn($h)=>$h->effective_from->lte($date))->sortByDesc('effective_from')->first();
        return $history
            ? ['hpp'=>(int)$history->hpp,'admin_percent'=>$history->admin_percent!==null?(float)$history->admin_percent:null]
            : ['hpp'=>null,'admin_percent'=>null];
    }

    public function variant(ProductVariant $variant, CarbonInterface $date): array
    {
        $variant->loadMissing(['costHistories','product.costHistories']);
        $variantHistory=$variant->costHistories->filter(fn($h)=>$h->effective_from->lte($date))->sortByDesc('effective_from')->first();
        $productCost=$variant->product ? $this->product($variant->product,$date) : ['hpp'=>null,'admin_percent'=>null];

        return [
            'hpp'=>$variantHistory ? (int)$variantHistory->hpp : $productCost['hpp'],
            'admin_percent'=>$variantHistory && $variantHistory->admin_percent!==null
                ? (float)$variantHistory->admin_percent
                : $productCost['admin_percent'],
            'hpp_source'=>$variantHistory ? 'variant' : ($productCost['hpp']!==null ? 'product' : null),
            'admin_source'=>$variantHistory && $variantHistory->admin_percent!==null
                ? 'variant'
                : ($productCost['admin_percent']!==null ? 'product' : null),
        ];
    }

    public function item(OrderItem $item, CarbonInterface $date): array
    {
        if($item->variant)return$this->variant($item->variant,$date);
        if(!$item->product)return['hpp'=>null,'admin_percent'=>null];

        $item->product->loadMissing(['variants.costHistories','variants.product.costHistories','costHistories']);
        $candidates=$item->product->variants;
        $sku=TextKey::normalize($item->reference_sku);
        if($sku!==''){
            $bySku=$candidates->filter(fn($v)=>TextKey::normalize($v->sku)===$sku);
            if($bySku->isNotEmpty())$candidates=$bySku;
        }
        if($candidates->isEmpty())return$this->product($item->product,$date);

        $resolved=[];
        foreach($candidates as $variant)$resolved[]=$this->variant($variant,$date);

        // Order lama yang variasinya ambigu hanya boleh memakai biaya kalau semua kandidat menghasilkan nilai sama.
        if(collect($resolved)->contains(fn($x)=>$x['hpp']===null))$hpp=null;
        else{
            $hppValues=array_values(array_unique(array_map(fn($x)=>(int)$x['hpp'],$resolved)));
            $hpp=count($hppValues)===1?$hppValues[0]:null;
        }
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
