<?php
namespace App\Services\Reports;

use App\Models\AdCostPeriod;
use App\Models\Store;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

final class AdSpendResolver
{
    public function resolve(Store $store, string $start, string $end): array
    {
        $from = Carbon::parse($start, 'Asia/Jakarta')->startOfDay();
        $to = Carbon::parse($end, 'Asia/Jakarta')->startOfDay();

        $periods = AdCostPeriod::where('store_id', $store->id)
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->orderBy('start_date')
            ->orderBy('end_date')
            ->get();

        $knownAmount = 0;
        $covered = [];
        $partial = [];
        $usable = [];

        foreach ($periods as $row) {
            $s = Carbon::parse($row->start_date)->startOfDay();
            $e = Carbon::parse($row->end_date)->startOfDay();
            $fullyInside = $s->gte($from) && $e->lte($to);

            if (!$fullyInside) {
                $partial[] = [
                    'id' => $row->id,
                    'start_date' => $s->toDateString(),
                    'end_date' => $e->toDateString(),
                    'amount' => (int) $row->amount,
                    'source' => $row->source,
                    'reason' => 'Periode iklan memotong batas laporan, sehingga nominalnya tidak boleh dialokasikan sebagian secara tebakan.',
                ];
                continue;
            }

            $knownAmount += (int) $row->amount;
            $usable[] = [
                'id' => $row->id,
                'start_date' => $s->toDateString(),
                'end_date' => $e->toDateString(),
                'amount' => (int) $row->amount,
                'source' => $row->source,
            ];
            foreach (CarbonPeriod::create($s, $e) as $date) {
                $covered[$date->toDateString()] = true;
            }
        }

        $required = CarbonPeriod::create($from, $to)->count();
        $coveredDays = count($covered);
        $coveragePercent = $required > 0 ? round(($coveredDays / $required) * 100, 1) : 0;
        $precise = empty($partial) && $coveredDays === $required;

        return [
            'amount' => $precise ? $knownAmount : null,
            'known_amount' => $knownAmount,
            'precise' => $precise,
            'covered_days' => $coveredDays,
            'required_days' => $required,
            'coverage_percent' => $coveragePercent,
            'usable_periods' => $usable,
            'partial_periods' => $partial,
            'message' => $precise
                ? 'Biaya iklan periode tersedia lengkap.'
                : ($partial
                    ? 'Ada periode iklan yang melewati batas filter. Profit setelah iklan tidak dihitung agar tidak memakai alokasi palsu.'
                    : 'Data biaya iklan belum mencakup seluruh tanggal pada periode laporan.'),
        ];
    }
}
