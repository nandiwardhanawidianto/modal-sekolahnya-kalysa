<?php
namespace App\Services\Reports;

use App\Models\Store;

final class GlobalReportService
{
    public function __construct(private ProfitReportService $reports) {}

    public function report(string $start,string $end): array
    {
        $stores=Store::where('active',true)->orderBy('name')->get();
        $rows=[]; $pending=[]; $issues=[];
        $totals=[
            'revenue_completed'=>0,'revenue_pending'=>0,'qty_pending'=>0,
            'profit_confirmed_before_ads'=>0,'profit_potential_pending_before_ads'=>0,'profit_projected_before_ads'=>0,
            'ad_spend'=>0,'ad_spend_known'=>0,'profit_confirmed_after_ads'=>null,'profit_projected_after_ads'=>null,
            'orders_completed'=>0,'orders_pending'=>0,'orders_pending_settlement'=>0,'orders_cancelled'=>0,
        ];
        $allAdsPrecise=true;

        foreach($stores as $store){
            $r=$this->reports->report($store,$start,$end); $m=$r['metrics'];
            foreach(['revenue_completed','revenue_pending','qty_pending','profit_confirmed_before_ads','profit_potential_pending_before_ads','profit_projected_before_ads','orders_completed','orders_pending','orders_pending_settlement','orders_cancelled'] as $k)$totals[$k]+=$m[$k]??0;
            $totals['ad_spend_known']+=$m['ad_spend_known']??0;
            if($m['ad_spend_precise'])$totals['ad_spend']+=(int)$m['ad_spend']; else $allAdsPrecise=false;
            foreach($r['pending'] as $p)$pending[]=$p+['store_id'=>$store->id,'store_name'=>$store->name];
            foreach($r['data_issues'] as $issue)$issues[]=$issue+['store_id'=>$store->id,'store_name'=>$store->name];
            $rows[]=['store'=>$r['store'],'metrics'=>$m,'coverage'=>$r['coverage']];
        }

        $totals['ad_spend_precise']=$allAdsPrecise;
        if($allAdsPrecise){
            $totals['profit_confirmed_after_ads']=$totals['profit_confirmed_before_ads']-$totals['ad_spend'];
            $totals['profit_projected_after_ads']=$totals['profit_projected_before_ads']-$totals['ad_spend'];
        } else {
            $totals['ad_spend']=null;
        }
        $totals['margin_confirmed_percent']=$totals['revenue_completed']>0&&$totals['profit_confirmed_after_ads']!==null?round($totals['profit_confirmed_after_ads']/$totals['revenue_completed']*100,2):null;
        $totals['roas_completed']=$allAdsPrecise&&($totals['ad_spend']??0)>0?round($totals['revenue_completed']/$totals['ad_spend'],2):null;
        $totals['has_pending']=($totals['orders_pending']+$totals['orders_pending_settlement'])>0;

        usort($pending,fn($a,$b)=>strcmp((string)($b['ordered_at']??''),(string)($a['ordered_at']??'')));
        return[
            'period'=>['start'=>$start,'end'=>$end],
            'totals'=>$totals,
            'stores'=>$rows,
            'pending'=>array_slice($pending,0,300),
            'data_issues'=>array_slice($issues,0,300),
        ];
    }
}
