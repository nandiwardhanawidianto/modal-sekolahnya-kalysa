<?php
namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\AdCostPeriod;
use App\Models\Store;
use App\Services\Reports\AdSpendResolver;
use App\Services\Reports\ClosingStaleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdsController extends Controller
{
    public function index(Request $request, Store $store, AdSpendResolver $resolver)
    {
        $start = $request->query('start', now('Asia/Jakarta')->startOfMonth()->toDateString());
        $end = $request->query('end', now('Asia/Jakarta')->toDateString());

        $rows = AdCostPeriod::where('store_id', $store->id)
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->orderBy('start_date')
            ->orderBy('end_date')
            ->get();

        return response()->json([
            'rows' => $rows,
            'resolution' => $resolver->resolve($store, $start, $end),
        ]);
    }

    public function storeRange(Request $request, Store $store, ClosingStaleService $closings)
    {
        $data = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'total_amount' => 'required|integer|min:0',
            'note' => 'nullable|string|max:255',
            'replace' => 'sometimes|boolean',
        ]);

        $overlaps = AdCostPeriod::where('store_id', $store->id)
            ->whereDate('start_date', '<=', $data['end_date'])
            ->whereDate('end_date', '>=', $data['start_date'])
            ->orderBy('start_date')
            ->get();

        $exactOnly = $overlaps->count() === 1
            && $overlaps->first()->start_date->toDateString() === $data['start_date']
            && $overlaps->first()->end_date->toDateString() === $data['end_date'];

        if ($overlaps->isNotEmpty() && !$exactOnly && !($data['replace'] ?? false)) {
            return response()->json([
                'message' => 'Periode ini bertumpang tindih dengan biaya iklan yang sudah tersimpan. Aktifkan replace hanya jika periode baru memang menggantikan seluruh data lama yang bertumpang tindih.',
                'overlaps' => $overlaps->map(fn($x) => [
                    'id' => $x->id,
                    'start_date' => $x->start_date->toDateString(),
                    'end_date' => $x->end_date->toDateString(),
                    'amount' => (int)$x->amount,
                ])->values(),
            ], 422);
        }

        if ($data['replace'] ?? false) {
            foreach ($overlaps as $row) {
                if ($row->start_date->toDateString() < $data['start_date'] || $row->end_date->toDateString() > $data['end_date']) {
                    return response()->json([
                        'message' => 'Replace ditolak karena periode baru hanya memotong sebagian periode lama. Hapus periode lama dulu atau masukkan range baru yang mencakupnya penuh.',
                    ], 422);
                }
            }
        }

        $period = DB::transaction(function () use ($request, $store, $data, $overlaps, $exactOnly) {
            if (($data['replace'] ?? false) || $exactOnly) {
                AdCostPeriod::whereIn('id', $overlaps->pluck('id'))->delete();
            }
            return AdCostPeriod::create([
                'store_id' => $store->id,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'amount' => (int)$data['total_amount'],
                'source' => 'manual',
                'note' => $data['note'] ?? null,
                'updated_by' => $request->user()->id,
            ]);
        });

        $closings->range($store->id, $data['start_date'], $data['end_date']);
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'store_id' => $store->id,
            'action' => 'ads.period_saved',
            'entity_type' => 'ad_cost_period',
            'entity_id' => (string)$period->id,
            'meta' => $data,
        ]);

        return response()->json(['ok' => true, 'period' => $period]);
    }

    public function destroy(Request $request, Store $store, AdCostPeriod $period, ClosingStaleService $closings)
    {
        abort_unless($period->store_id === $store->id, 404);
        $start = $period->start_date->toDateString();
        $end = $period->end_date->toDateString();
        $id = $period->id;
        $period->delete();
        $closings->range($store->id, $start, $end);
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'store_id' => $store->id,
            'action' => 'ads.period_deleted',
            'entity_type' => 'ad_cost_period',
            'entity_id' => (string)$id,
            'meta' => ['start_date' => $start, 'end_date' => $end],
        ]);
        return response()->json(['ok' => true]);
    }
}
