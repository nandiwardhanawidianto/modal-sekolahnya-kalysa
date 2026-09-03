<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductCostHistory;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreFeeHistory;
use App\Models\VariantCostHistory;
use App\Services\Reports\ClosingStaleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CostController extends Controller
{
    // Penanda teknis: biaya pertama berlaku untuk seluruh histori lama.
    private const BASELINE_DATE = '2000-01-01';

    public function productCosts(
        Request $r,
        Store $store,
        ClosingStaleService $closings
    ) {
        $d = $r->validate([
            'effective_from' => 'required|date',
            'rows' => 'required|array|min:1',
            'rows.*.product_id' => 'required|integer',
            'rows.*.hpp' => 'required|integer|min:0',
            'rows.*.admin_percent' => 'nullable|numeric|min:0|max:100',
        ]);

        $ids = collect($d['rows'])->pluck('product_id');

        $valid = Product::where('store_id', $store->id)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();

        if (count($valid) !== count(array_unique($ids->all()))) {
            return response()->json([
                'message' => 'Ada produk yang bukan milik toko ini.'
            ], 422);
        }

        $baselineCount = 0;
        $earliestTouched = $d['effective_from'];

        DB::transaction(function () use (
            $d,
            $r,
            &$baselineCount,
            &$earliestTouched
        ) {
            foreach ($d['rows'] as $row) {

                $hasHistory = ProductCostHistory::where(
                    'product_id',
                    $row['product_id']
                )->exists();

                // HPP pertama = baseline.
                $effectiveFrom = $hasHistory
                    ? $d['effective_from']
                    : self::BASELINE_DATE;

                if (!$hasHistory) {
                    $baselineCount++;
                    $earliestTouched = self::BASELINE_DATE;
                }

                ProductCostHistory::updateOrCreate(
                    [
                        'product_id' => $row['product_id'],
                        'effective_from' => $effectiveFrom,
                    ],
                    [
                        'hpp' => $row['hpp'],
                        'admin_percent' => $row['admin_percent'] ?? null,
                        'created_by' => $r->user()->id,
                    ]
                );
            }
        });

        $closings->range($store->id, $earliestTouched);

        ActivityLog::create([
            'user_id' => $r->user()->id,
            'store_id' => $store->id,
            'action' => 'cost.products_updated',
            'meta' => [
                'requested_effective_from' => $d['effective_from'],
                'baseline_count' => $baselineCount,
                'count' => count($d['rows']),
            ],
        ]);

        return response()->json([
            'ok' => true,
            'baseline_count' => $baselineCount,
        ]);
    }

    public function variantCosts(
        Request $r,
        Store $store,
        ClosingStaleService $closings
    ) {
        $d = $r->validate([
            'effective_from' => 'required|date',
            'rows' => 'required|array|min:1',
            'rows.*.variant_id' => 'required|integer',
            'rows.*.hpp' => 'required|integer|min:0',
            'rows.*.admin_percent' => 'nullable|numeric|min:0|max:100',
        ]);

        $ids = collect($d['rows'])->pluck('variant_id');

        $valid = ProductVariant::where('store_id', $store->id)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();

        if (count($valid) !== count(array_unique($ids->all()))) {
            return response()->json([
                'message' => 'Ada variasi yang bukan milik toko ini.'
            ], 422);
        }

        $baselineCount = 0;
        $earliestTouched = $d['effective_from'];

        DB::transaction(function () use (
            $d,
            $r,
            &$baselineCount,
            &$earliestTouched
        ) {
            foreach ($d['rows'] as $row) {

                $hasHistory = VariantCostHistory::where(
                    'product_variant_id',
                    $row['variant_id']
                )->exists();

                $effectiveFrom = $hasHistory
                    ? $d['effective_from']
                    : self::BASELINE_DATE;

                if (!$hasHistory) {
                    $baselineCount++;
                    $earliestTouched = self::BASELINE_DATE;
                }

                VariantCostHistory::updateOrCreate(
                    [
                        'product_variant_id' => $row['variant_id'],
                        'effective_from' => $effectiveFrom,
                    ],
                    [
                        'hpp' => $row['hpp'],
                        'admin_percent' => $row['admin_percent'] ?? null,
                        'created_by' => $r->user()->id,
                    ]
                );
            }
        });

        $closings->range($store->id, $earliestTouched);

        ActivityLog::create([
            'user_id' => $r->user()->id,
            'store_id' => $store->id,
            'action' => 'cost.variants_updated',
            'meta' => [
                'requested_effective_from' => $d['effective_from'],
                'baseline_count' => $baselineCount,
                'count' => count($d['rows']),
            ],
        ]);

        return response()->json([
            'ok' => true,
            'baseline_count' => $baselineCount,
        ]);
    }

    public function storeFee(
        Request $r,
        Store $store,
        ClosingStaleService $closings
    ) {
        $d = $r->validate([
            'effective_from' => 'required|date',
            'default_admin_percent' => 'required|numeric|min:0|max:100',
            'fixed_fee_per_order' => 'required|integer|min:0',
        ]);

        $hasHistory = StoreFeeHistory::where(
            'store_id',
            $store->id
        )->exists();

        // Admin toko pertama juga baseline.
        $effectiveFrom = $hasHistory
            ? $d['effective_from']
            : self::BASELINE_DATE;

        $fee = StoreFeeHistory::updateOrCreate(
            [
                'store_id' => $store->id,
                'effective_from' => $effectiveFrom,
            ],
            [
                'default_admin_percent' => $d['default_admin_percent'],
                'fixed_fee_per_order' => $d['fixed_fee_per_order'],
                'created_by' => $r->user()->id,
            ]
        );

        $closings->range($store->id, $effectiveFrom);

        ActivityLog::create([
            'user_id' => $r->user()->id,
            'store_id' => $store->id,
            'action' => 'store_fee.updated',
            'meta' => [
                ...$d,
                'actual_effective_from' => $effectiveFrom,
                'is_baseline' => !$hasHistory,
            ],
        ]);

        return response()->json([
            'fee' => $fee,
            'is_baseline' => !$hasHistory,
        ]);
    }

    public function feeHistory(Store $store)
    {
        return response()->json([
            'fees' => $store->feeHistories()
                ->orderByDesc('effective_from')
                ->get(),
        ]);
    }
}