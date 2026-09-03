<?php
namespace App\Http\Controllers;
use App\Models\ActivityLog; use App\Models\Store; use Illuminate\Http\Request;
class StoreController extends Controller
{
 public function index(){return response()->json(['stores'=>Store::where('active',true)->orderBy('name')->get()]);}
 public function store(Request $r){$d=$r->validate(['name'=>'required|string|max:120|unique:stores,name','shopee_username'=>'nullable|string|max:120','shopee_shop_id'=>'nullable|string|max:80']);$s=Store::create($d+['active'=>true]);ActivityLog::create(['user_id'=>$r->user()->id,'store_id'=>$s->id,'action'=>'store.created','entity_type'=>'store','entity_id'=>(string)$s->id]);return response()->json(['store'=>$s],201);}
 public function update(Request $r,Store $store){$d=$r->validate(['name'=>'sometimes|required|string|max:120|unique:stores,name,'.$store->id,'shopee_username'=>'nullable|string|max:120','shopee_shop_id'=>'nullable|string|max:80','active'=>'sometimes|boolean']);$store->update($d);ActivityLog::create(['user_id'=>$r->user()->id,'store_id'=>$store->id,'action'=>'store.updated','entity_type'=>'store','entity_id'=>(string)$store->id,'meta'=>$d]);return response()->json(['store'=>$store->fresh()]);}
}
