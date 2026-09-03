<?php
namespace App\Services\Reports;

use App\Models\Adjustment;
use App\Models\Settlement;
use App\Models\Store;
use Carbon\Carbon;

final class CashflowReportService
{
    public function __construct(private AdSpendResolver $ads) {}

    public function report(Store $store,string $start,string $end): array
    {
        $from=Carbon::parse($start)->toDateString(); $to=Carbon::parse($end)->toDateString();
        $settlements=Settlement::where('store_id',$store->id)->whereBetween('released_at',[$from,$to])->orderBy('released_at')->get();
        $adjustments=Adjustment::where('store_id',$store->id)->whereBetween('adjustment_date',[$from,$to])->orderBy('adjustment_date')->get();
        $ad=$this->ads->resolve($store,$from,$to);
        $income=(int)$settlements->sum('actual_income'); $adjust=(int)$adjustments->sum('amount'); $byOrderMonth=[];
        foreach($settlements as $s){$month=$s->order_date?->format('Y-m')??'unknown';$byOrderMonth[$month]=($byOrderMonth[$month]??0)+(int)$s->actual_income;} ksort($byOrderMonth);
        return [
            'store'=>['id'=>$store->id,'name'=>$store->name],
            'period'=>['start'=>$from,'end'=>$to],
            'metrics'=>[
                'released_income'=>$income,
                'adjustments'=>$adjust,
                'released_plus_adjustments'=>$income+$adjust,
                'ads'=>$ad['amount'],'ads_known'=>$ad['known_amount'],'ads_precise'=>$ad['precise'],
                'business_comparison_after_ads'=>$ad['precise']?$income+$adjust-$ad['amount']:null,
                'settlement_count'=>$settlements->count(),'adjustment_count'=>$adjustments->count(),
            ],
            'ads'=>$ad,
            'by_order_month'=>$byOrderMonth,
            'settlements'=>$settlements->take(200)->values(),
            'adjustment_rows'=>$adjustments->take(200)->values(),
            'note'=>'File Penghasilan menunjukkan dana yang dilepas per order, bukan penarikan bank. Angka iklan di halaman ini hanya pembanding periode; jangan dipakai sebagai rekonsiliasi Saldo Penjual sampai riwayat wallet/top-up/penarikan tersedia.',
        ];
    }
}
