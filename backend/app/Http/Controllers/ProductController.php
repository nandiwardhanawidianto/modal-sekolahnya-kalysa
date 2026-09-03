<?php
namespace App\Http\Controllers;

use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\VariantCostHistory;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request, Store $store)
    {
        $q=ProductVariant::with(['product','costHistories'=>fn($q)=>$q->orderByDesc('effective_from')])->where('store_id',$store->id);
        if($s=trim((string)$request->query('q'))) $q->where(function($x)use($s){$x->where('name','like',"%{$s}%")->orWhere('sku','like',"%{$s}%")->orWhereHas('product',fn($p)=>$p->where('name','like',"%{$s}%"));});
        $variants=$q->orderBy('id')->paginate(500);
        $variants->getCollection()->transform(function($v){$latest=$v->costHistories->first();return['id'=>$v->id,'shopee_variation_id'=>$v->shopee_variation_id,'product_name'=>$v->product?->name,'variation_name'=>$v->name,'sku'=>$v->sku,'current_price'=>$v->current_price,'stock'=>$v->stock,'minimum_purchase'=>$v->minimum_purchase,'hpp'=>$latest?->hpp,'admin_percent'=>$latest?->admin_percent,'cost_effective_from'=>$latest?->effective_from?->toDateString()];});
        $payload=$variants->toArray();
        $earliest=$store->orders()->whereNotNull('ordered_at')->min('ordered_at');
        $payload['meta']=[
            'earliest_order_date'=>$earliest?substr((string)$earliest,0,10):null,
            'has_any_cost_history'=>VariantCostHistory::whereIn('product_variant_id',ProductVariant::where('store_id',$store->id)->select('id'))->exists(),
        ];
        return response()->json($payload);
    }
}
