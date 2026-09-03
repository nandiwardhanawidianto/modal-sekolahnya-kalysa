<?php
namespace App\Http\Controllers;
use App\Models\Store; use App\Services\Reports\CashflowReportService; use App\Services\Reports\GlobalReportService; use App\Services\Reports\ProfitReportService; use Illuminate\Http\Request;
class ReportController extends Controller
{
 public function store(Request $r,Store $store,ProfitReportService $s){$d=$r->validate(['start'=>'required|date','end'=>'required|date|after_or_equal:start']);return response()->json($s->report($store,$d['start'],$d['end']));}
 public function cashflow(Request $r,Store $store,CashflowReportService $s){$d=$r->validate(['start'=>'required|date','end'=>'required|date|after_or_equal:start']);return response()->json($s->report($store,$d['start'],$d['end']));}
 public function global(Request $r,GlobalReportService $s){$d=$r->validate(['start'=>'required|date','end'=>'required|date|after_or_equal:start']);return response()->json($s->report($d['start'],$d['end']));}
}
