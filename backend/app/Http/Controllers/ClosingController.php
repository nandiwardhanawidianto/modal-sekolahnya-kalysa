<?php
namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\MonthlyClosing;
use App\Models\Store;
use App\Services\Reports\ProfitReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ClosingController extends Controller
{
    public function index(Store $store)
    {
        return response()->json(['closings'=>MonthlyClosing::where('store_id',$store->id)->orderByDesc('month')->get()]);
    }

    public function close(Request $request, Store $store, ProfitReportService $reports)
    {
        $data=$request->validate(['month'=>'required|date_format:Y-m','force'=>'sometimes|boolean']);
        $month=Carbon::createFromFormat('Y-m',$data['month'],'Asia/Jakarta')->startOfMonth();
        $snapshot=$reports->report($store,$month->toDateString(),$month->copy()->endOfMonth()->toDateString());

        if(!$snapshot['coverage']['is_final']&&!($data['force']??false)){
            return response()->json([
                'message'=>'Bulan ini belum final. Masih ada data pending/kosong atau coverage iklan belum presisi. Kamu tetap bisa simpan snapshot sementara dengan konfirmasi paksa.',
                'coverage'=>$snapshot['coverage'],
                'metrics'=>[
                    'orders_pending'=>$snapshot['metrics']['orders_pending'],
                    'orders_pending_settlement'=>$snapshot['metrics']['orders_pending_settlement'],
                    'orders_missing_hpp'=>$snapshot['metrics']['orders_missing_hpp'],
                    'ad_spend_precise'=>$snapshot['metrics']['ad_spend_precise'],
                ],
            ],422);
        }

        $closing=MonthlyClosing::updateOrCreate(
            ['store_id'=>$store->id,'month'=>$month->toDateString()],
            ['snapshot'=>$snapshot,'is_stale'=>false,'closed_by'=>$request->user()->id,'closed_at'=>now()]
        );
        ActivityLog::create(['user_id'=>$request->user()->id,'store_id'=>$store->id,'action'=>'month.closed','entity_type'=>'monthly_closing','entity_id'=>(string)$closing->id,'meta'=>['month'=>$data['month'],'forced'=>(bool)($data['force']??false),'was_final'=>$snapshot['coverage']['is_final']]]);
        return response()->json(['closing'=>$closing]);
    }
}
