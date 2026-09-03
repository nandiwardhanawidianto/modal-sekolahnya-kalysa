<?php
namespace App\Services\Reports;

use App\Models\DataCoverageDay;
use App\Models\Order;
use App\Models\Store;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

final class ProfitReportService
{
    public function __construct(private CostResolver $costs, private AdSpendResolver $ads) {}

    public function report(Store $store, string $start, string $end): array
    {
        $from = Carbon::parse($start, 'Asia/Jakarta')->startOfDay();
        $to = Carbon::parse($end, 'Asia/Jakarta')->endOfDay();
        $store->load(['feeHistories']);
        $orders = Order::with(['items.variant.costHistories','items.variant.product.costHistories','items.product.costHistories','items.product.variants.costHistories','items.product.variants.product.costHistories','settlement','adjustments'])
            ->where('store_id',$store->id)->whereBetween('ordered_at',[$from,$to])->orderBy('ordered_at')->get();
        $ad = $this->ads->resolve($store, $from->toDateString(), $to->toDateString());

        $m = [
            'orders_total'=>0,'orders_completed'=>0,'orders_cancelled'=>0,'orders_pending'=>0,'orders_pending_settlement'=>0,'orders_refund'=>0,'orders_missing_hpp'=>0,
            'qty_total'=>0,'qty_completed'=>0,'qty_pending'=>0,'qty_cancelled'=>0,'returned_qty'=>0,
            'revenue_completed'=>0,'revenue_pending'=>0,'revenue_cancelled'=>0,
            'settlement_actual'=>0,'buyer_refund_reported'=>0,'adjustments'=>0,'adjustment_debits'=>0,'adjustment_credits'=>0,
            'hpp_confirmed'=>0,'hpp_pending'=>0,'expected_settlement'=>0,'expected_profit_before_ads'=>0,
            'profit_confirmed_before_ads'=>0,'profit_potential_pending_before_ads'=>0,'missing_hpp_items'=>0,'mapped_items'=>0,'items_non_cancelled'=>0,'orders_missing_fee_config'=>0,
        ];
        $pending=[]; $dataIssues=[]; $anomalies=[];

        foreach ($orders as $order) {
            $m['orders_total']++;
            $cancelled=$this->cancelled($order->status); $completed=$this->completed($order->status);
            $refunded=$order->returned_qty>0||trim((string)$order->return_status)!=='';
            $qty=(int)$order->total_qty; $revenue=(int)$order->product_revenue;
            $m['qty_total']+=$qty; $m['returned_qty']+=(int)$order->returned_qty; if($refunded)$m['orders_refund']++;

            if($cancelled){$m['orders_cancelled']++;$m['qty_cancelled']+=$qty;$m['revenue_cancelled']+=$revenue;continue;}
            if($completed){$m['orders_completed']++;$m['qty_completed']+=$qty;$m['revenue_completed']+=$revenue;}
            else{$m['orders_pending']++;$m['qty_pending']+=$qty;$m['revenue_pending']+=$revenue;}

            $fee=$this->costs->storeFee($store,$order->ordered_at??$from); $fixed=(int)$fee['fixed_fee_per_order']; if(!$fee['configured'])$m['orders_missing_fee_config']++;
            $estimatedAdmin=0; $hpp=0; $completeHpp=true;
            foreach($order->items as $item){
                $m['items_non_cancelled']++; $cost=$this->costs->item($item,$order->ordered_at??$from);
                $admin=$cost['admin_percent']??$fee['default_admin_percent'];
                $estimatedAdmin+=(int)round((int)$item->subtotal*((float)$admin/100));
                if($cost['hpp']===null){$completeHpp=false;$m['missing_hpp_items']++;continue;}
                $m['mapped_items']++; $hpp+=(int)$cost['hpp']*(int)$item->qty;
            }
            if(!$completeHpp)$m['orders_missing_hpp']++;

            $expectedSettlement=$fee['configured']?$revenue-$estimatedAdmin-$fixed:null;
            $expectedProfit=$completeHpp&&$expectedSettlement!==null?$expectedSettlement-$hpp:null;
            if($expectedSettlement!==null)$m['expected_settlement']+=$expectedSettlement;
            if($expectedProfit!==null)$m['expected_profit_before_ads']+=$expectedProfit;

            $settlement=$order->settlement; $adjust=(int)$order->adjustments->sum('amount');
            foreach($order->adjustments as $adj){$amt=(int)$adj->amount;if($amt<0)$m['adjustment_debits']+=abs($amt);elseif($amt>0)$m['adjustment_credits']+=$amt;}

            if($settlement){
                $m['settlement_actual']+=(int)$settlement->actual_income; $m['buyer_refund_reported']+=abs((int)$settlement->buyer_refund); $m['adjustments']+=$adjust;
                if(!$completeHpp)$dataIssues[]=['order_number'=>$order->order_number,'ordered_at'=>$order->ordered_at?->toDateTimeString(),'status'=>$order->status,'qty'=>$qty,'revenue'=>$revenue,'issue'=>'Settlement sudah ada, tetapi HPP produk/variasi belum lengkap. Profit belum dihitung.'];
                if($completeHpp){
                    $m['hpp_confirmed']+=$hpp; $actualBeforeAds=(int)$settlement->actual_income+$adjust-$hpp; $m['profit_confirmed_before_ads']+=$actualBeforeAds;
                    $variance=$expectedSettlement!==null?(((int)$settlement->actual_income+$adjust)-$expectedSettlement):null;
                    if($variance!==null&&abs($variance)>=1)$anomalies[]=['order_number'=>$order->order_number,'ordered_at'=>$order->ordered_at?->toDateTimeString(),'expected_settlement'=>$expectedSettlement,'actual_settlement'=>(int)$settlement->actual_income,'adjustments'=>$adjust,'variance'=>$variance,'profit_before_ads'=>$actualBeforeAds];
                }
            } else {
                $m['orders_pending_settlement'] += $completed?1:0;
                if($completeHpp){$m['hpp_pending']+=$hpp;if($expectedProfit!==null)$m['profit_potential_pending_before_ads']+=$expectedProfit;}
                $pending[]=[
                    'order_number'=>$order->order_number,'status'=>$order->status,'ordered_at'=>$order->ordered_at?->toDateTimeString(),'qty'=>$qty,'revenue'=>$revenue,
                    'estimated_profit_before_ads'=>$expectedProfit,
                    'reason'=>($completed?'Selesai, dana belum ditemukan di file Penghasilan':'Pesanan belum selesai').(!$completeHpp?' · HPP belum lengkap':'').(!$fee['configured']?' · Fee toko belum dikonfigurasi untuk tanggal order':''),
                    'age_days'=>$order->ordered_at?->diffInDays(now('Asia/Jakarta')),
                ];
            }
        }

        $m['ad_spend']=$ad['amount'];
        $m['ad_spend_known']=$ad['known_amount'];
        $m['ad_spend_precise']=$ad['precise'];
        $m['profit_projected_before_ads']=$m['profit_confirmed_before_ads']+$m['profit_potential_pending_before_ads'];
        $m['profit_confirmed_after_ads']=$ad['precise']?$m['profit_confirmed_before_ads']-$ad['amount']:null;
        $m['profit_projected_after_ads']=$ad['precise']?$m['profit_projected_before_ads']-$ad['amount']:null;
        $m['after_ads_status']=!$ad['precise']?'ads_incomplete':(($m['orders_pending']>0||$m['orders_pending_settlement']>0||$m['orders_missing_hpp']>0)?'provisional':'confirmed');
        $m['margin_confirmed_percent']=$m['revenue_completed']>0&&$m['profit_confirmed_after_ads']!==null?round($m['profit_confirmed_after_ads']/$m['revenue_completed']*100,2):null;
        $m['roas_completed']=$ad['precise']&&$ad['amount']>0?round($m['revenue_completed']/$ad['amount'],2):null;
        $m['poas_confirmed']=$ad['precise']&&$ad['amount']>0?round($m['profit_confirmed_before_ads']/$ad['amount'],2):null;

        usort($anomalies,fn($a,$b)=>abs($b['variance'])<=>abs($a['variance']));
        return [
            'store'=>['id'=>$store->id,'name'=>$store->name],
            'period'=>['start'=>$from->toDateString(),'end'=>$to->toDateString()],
            'metrics'=>$m,
            'pending'=>$pending,
            'data_issues'=>$dataIssues,
            'anomalies'=>array_slice($anomalies,0,100),
            'ads'=>$ad,
            'coverage'=>$this->coverage($store,$from,$to,$m,$ad),
            'generated_at'=>now('Asia/Jakarta')->toIso8601String(),
        ];
    }

    private function coverage(Store $store, Carbon $from, Carbon $to, array $metrics, array $ads): array
    {
        $days=CarbonPeriod::create($from->copy()->startOfDay(),$to->copy()->startOfDay())->count();
        $orderDays=DataCoverageDay::where('store_id',$store->id)->where('type','orders')->whereBetween('date',[$from->toDateString(),$to->toDateString()])->count();
        $latestIncome=DataCoverageDay::where('store_id',$store->id)->where('type','income')->max('date');
        $completed=max(1,$metrics['orders_completed']); $settledCompleted=$metrics['orders_completed']-$metrics['orders_pending_settlement']; $itemDen=max(1,$metrics['items_non_cancelled']);
        return [
            'order_days'=>['covered'=>$orderDays,'required'=>$days,'percent'=>round($orderDays/$days*100,1)],
            'ads'=>['covered'=>$ads['covered_days'],'required'=>$ads['required_days'],'percent'=>$ads['coverage_percent'],'precise'=>$ads['precise'],'message'=>$ads['message']],
            'settlement'=>['completed_orders'=>$metrics['orders_completed'],'settled_completed_orders'=>$settledCompleted,'percent'=>round($settledCompleted/$completed*100,1),'latest_income_file_date'=>$latestIncome],
            'hpp'=>['items'=>$metrics['items_non_cancelled'],'missing'=>$metrics['missing_hpp_items'],'percent'=>round(($itemDen-$metrics['missing_hpp_items'])/$itemDen*100,1)],
            'fees'=>['orders_missing'=>$metrics['orders_missing_fee_config'],'percent'=>$metrics['orders_total']-$metrics['orders_cancelled']>0?round((($metrics['orders_total']-$metrics['orders_cancelled']-$metrics['orders_missing_fee_config'])/($metrics['orders_total']-$metrics['orders_cancelled']))*100,1):100],
            'is_final'=>$orderDays===$days&&$ads['precise']&&$metrics['orders_pending_settlement']===0&&$metrics['missing_hpp_items']===0&&$metrics['orders_missing_hpp']===0&&$metrics['orders_pending']===0,
        ];
    }

    private function cancelled(?string $status): bool { $s=mb_strtolower(trim((string)$status)); return str_contains($s,'batal'); }
    private function completed(?string $status): bool { $s=mb_strtolower(trim((string)$status)); return $s==='selesai'||str_contains($s,'selesai'); }
}
