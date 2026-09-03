<?php
namespace App\Services\Reports;
use App\Models\MonthlyClosing;
use Carbon\Carbon;
final class ClosingStaleService
{
 public function range(int $storeId,?string $start,?string $end=null): void
 {
  if(!$start)return;$from=Carbon::parse($start)->startOfMonth()->toDateString();$to=$end?Carbon::parse($end)->startOfMonth()->toDateString():null;
  $q=MonthlyClosing::where('store_id',$storeId)->whereDate('month','>=',$from);if($to)$q->whereDate('month','<=',$to);$q->update(['is_stale'=>true]);
 }
 public function all(int $storeId): void {MonthlyClosing::where('store_id',$storeId)->update(['is_stale'=>true]);}
}
