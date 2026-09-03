<?php
namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductCostHistory;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\VariantCostHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function index(Request $request, Store $store)
    {
        $status=(string)$request->query('status','active');
        $q=Product::with([
            'costHistories'=>fn($q)=>$q->orderByDesc('effective_from'),
            'variants.costHistories'=>fn($q)=>$q->orderByDesc('effective_from'),
        ])->where('store_id',$store->id);

        if($status==='archived')$q->where('active',false);
        elseif($status!=='all')$q->where('active',true);

        if($s=trim((string)$request->query('q'))){
            $q->where(function($x)use($s){
                $x->where('name','like',"%{$s}%")
                    ->orWhere('parent_sku','like',"%{$s}%")
                    ->orWhereHas('variants',fn($v)=>$v->where('name','like',"%{$s}%")->orWhere('sku','like',"%{$s}%"));
            });
        }

        $products=$q->orderBy('name')->paginate(200);
        $products->getCollection()->transform(function($p){
            $pc=$p->costHistories->first();
            $variants=$p->variants->sortBy('id')->values()->map(function($v)use($pc){
                $vc=$v->costHistories->first();
                return [
                    'id'=>$v->id,
                    'shopee_variation_id'=>$v->shopee_variation_id,
                    'variation_name'=>$v->name,
                    'sku'=>$v->sku,
                    'current_price'=>$v->current_price,
                    'stock'=>$v->stock,
                    'minimum_purchase'=>$v->minimum_purchase,
                    'active'=>(bool)$v->active,
                    'override_hpp'=>$vc?->hpp,
                    'override_admin_percent'=>$vc?->admin_percent,
                    'override_effective_from'=>$vc?->effective_from?->toDateString(),
                    'effective_hpp'=>$vc?->hpp ?? $pc?->hpp,
                    'effective_admin_percent'=>$vc && $vc->admin_percent!==null ? $vc->admin_percent : $pc?->admin_percent,
                    'hpp_source'=>$vc?'variation':($pc?'product':null),
                    'admin_source'=>$vc && $vc->admin_percent!==null?'variation':($pc && $pc->admin_percent!==null?'product':'store'),
                ];
            });
            return [
                'id'=>$p->id,
                'shopee_product_id'=>$p->shopee_product_id,
                'product_name'=>$p->name,
                'parent_sku'=>$p->parent_sku,
                'active'=>(bool)$p->active,
                'default_hpp'=>$pc?->hpp,
                'default_admin_percent'=>$pc?->admin_percent,
                'cost_effective_from'=>$pc?->effective_from?->toDateString(),
                'variants_count'=>$variants->count(),
                'variants'=>$variants,
            ];
        });

        $payload=$products->toArray();
        $earliest=$store->orders()->whereNotNull('ordered_at')->min('ordered_at');
        $hasProductCosts=ProductCostHistory::whereIn('product_id',Product::where('store_id',$store->id)->select('id'))->exists();
        $hasVariantCosts=VariantCostHistory::whereIn('product_variant_id',ProductVariant::where('store_id',$store->id)->select('id'))->exists();
        $payload['meta']=[
            'earliest_order_date'=>$earliest?substr((string)$earliest,0,10):null,
            'has_any_cost_history'=>$hasProductCosts||$hasVariantCosts,
            'status'=>$status,
            'active_products'=>Product::where('store_id',$store->id)->where('active',true)->count(),
            'archived_products'=>Product::where('store_id',$store->id)->where('active',false)->count(),
        ];
        return response()->json($payload);
    }

    public function status(Request $request, Store $store, Product $product)
    {
        if((int)$product->store_id!==(int)$store->id)abort(404);
        $data=$request->validate(['active'=>'required|boolean']);
        $active=(bool)$data['active'];

        DB::transaction(function()use($product,$active){
            $product->update(['active'=>$active]);
            ProductVariant::where('product_id',$product->id)->update(['active'=>$active]);
        });

        ActivityLog::create([
            'user_id'=>$request->user()->id,
            'store_id'=>$store->id,
            'action'=>$active?'product.restored':'product.archived',
            'entity_type'=>'product',
            'entity_id'=>(string)$product->id,
            'meta'=>['name'=>$product->name,'shopee_product_id'=>$product->shopee_product_id],
        ]);

        return response()->json([
            'product'=>$product->fresh(),
            'message'=>$active?'Produk diaktifkan kembali.':'Produk diarsipkan. Histori order dan HPP tidak dihapus.',
        ]);
    }
}
