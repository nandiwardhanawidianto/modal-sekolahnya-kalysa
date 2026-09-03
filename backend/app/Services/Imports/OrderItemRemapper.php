<?php
namespace App\Services\Imports;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\TextKey;

final class OrderItemRemapper
{
    public function remap(int $storeId): array
    {
        $products=Product::where('store_id',$storeId)->get();
        $productNameCandidates=[];$parentSkuCandidates=[];$productNames=[];
        foreach($products as $p){
            $productNames[$p->id]=$p->name;
            $productNameCandidates[TextKey::normalize($p->name)][]=$p->id;
            if(TextKey::normalize($p->parent_sku)!=='')$parentSkuCandidates[TextKey::normalize($p->parent_sku)][]=$p->id;
        }
        $unique=fn(array $c)=>array_filter(array_map(fn($ids)=>count(array_unique($ids))===1?array_values(array_unique($ids))[0]:null,$c),fn($v)=>$v!==null);
        $productNameMap=$unique($productNameCandidates);$parentSkuMap=$unique($parentSkuCandidates);

        $variants=ProductVariant::where('store_id',$storeId)->get();
        $exact=[];$canonical=[];$sku=[];$globalExact=[];$globalCanonical=[];$globalSku=[];$variantProduct=[];
        foreach($variants as $v){
            $variantProduct[$v->id]=$v->product_id;
            $exact[$v->product_id.'|'.TextKey::normalize($v->name)][]=$v->id;
            $canonical[$v->product_id.'|'.TextKey::canonicalVariation($v->name)][]=$v->id;
            $pk=TextKey::normalize($productNames[$v->product_id]??'');
            $globalExact[$pk.'|'.TextKey::normalize($v->name)][]=$v->id;
            $globalCanonical[$pk.'|'.TextKey::canonicalVariation($v->name)][]=$v->id;
            if($v->sku){
                $sku[$v->product_id.'|'.TextKey::normalize($v->sku)][]=$v->id;
                $globalSku[TextKey::normalize($v->sku)][]=$v->id;
            }
        }
        $exact=$unique($exact);$canonical=$unique($canonical);$sku=$unique($sku);$globalExact=$unique($globalExact);$globalCanonical=$unique($globalCanonical);$globalSku=$unique($globalSku);
        $productCount=0;$variantCount=0;

        OrderItem::where('store_id',$storeId)->where(function($q){$q->whereNull('product_id')->orWhereNull('product_variant_id');})->orderBy('id')->chunkById(500,function($items)use($productNameMap,$parentSkuMap,$exact,$canonical,$sku,$globalExact,$globalCanonical,$globalSku,$variantProduct,&$productCount,&$variantCount){
            foreach($items as $item){
                $productId=$item->product_id;
                if(!$productId&&TextKey::normalize($item->parent_sku)!=='')$productId=$parentSkuMap[TextKey::normalize($item->parent_sku)]??null;
                if(!$productId)$productId=$productNameMap[TextKey::normalize($item->product_name)]??null;

                $variantId=$item->product_variant_id;
                if(!$variantId&&$productId){
                    $variantId=$exact[$productId.'|'.TextKey::normalize($item->variation_name)]??($canonical[$productId.'|'.TextKey::canonicalVariation($item->variation_name)]??null);
                    if(!$variantId&&TextKey::normalize($item->reference_sku)!=='')$variantId=$sku[$productId.'|'.TextKey::normalize($item->reference_sku)]??null;
                }
                if(!$variantId){
                    $pk=TextKey::normalize($item->product_name);
                    $variantId=$globalExact[$pk.'|'.TextKey::normalize($item->variation_name)]??($globalCanonical[$pk.'|'.TextKey::canonicalVariation($item->variation_name)]??null);
                    if(!$variantId&&TextKey::normalize($item->reference_sku)!=='')$variantId=$globalSku[TextKey::normalize($item->reference_sku)]??null;
                }
                if(!$productId&&$variantId)$productId=$variantProduct[$variantId]??null;

                $changed=false;
                if(!$item->product_id&&$productId){$item->product_id=$productId;$productCount++;$changed=true;}
                if(!$item->product_variant_id&&$variantId){$item->product_variant_id=$variantId;$variantCount++;$changed=true;}
                if($changed)$item->save();
            }
        });
        return['products_linked'=>$productCount,'variants_linked'=>$variantCount];
    }
}
