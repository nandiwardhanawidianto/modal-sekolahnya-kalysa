<?php

namespace App\Services\Reports;

use App\Models\Order;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Support\Collection;

final class SimpleDashboardService
{
    public function __construct(
        private CostResolver $costs,
        private AdSpendResolver $ads,
    ) {}

    public function report(string $start, string $end, ?Store $onlyStore = null): array
    {
        $from = Carbon::parse($start, 'Asia/Jakarta')->startOfDay();
        $to = Carbon::parse($end, 'Asia/Jakarta')->endOfDay();
        $stores = $onlyStore
            ? collect([$onlyStore])
            : Store::where('active', true)->orderBy('name')->get();

        $current = $this->aggregate($stores, $from, $to, true);

        $days = $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        $previousTo = $from->copy()->subDay()->endOfDay();
        $previousFrom = $previousTo->copy()->subDays($days - 1)->startOfDay();
        $previous = $this->aggregate($stores, $previousFrom, $previousTo, false);

        return [
            'scope' => $onlyStore
                ? ['type' => 'store', 'store_id' => $onlyStore->id, 'name' => $onlyStore->name]
                : ['type' => 'all', 'store_id' => null, 'name' => 'Semua Toko'],
            'period' => ['start' => $from->toDateString(), 'end' => $to->toDateString()],
            'previous_period' => ['start' => $previousFrom->toDateString(), 'end' => $previousTo->toDateString()],
            'has_data' => $current['has_data'],
            'metrics' => $current['metrics'],
            'comparison' => [
                'revenue_percent' => $this->change($current['metrics']['revenue'], $previous['metrics']['revenue']),
                'qty_percent' => $this->change($current['metrics']['qty_sold'], $previous['metrics']['qty_sold']),
                'profit_percent' => $current['metrics']['profit_after_ads'] !== null && $previous['metrics']['profit_after_ads'] !== null
                    ? $this->change($current['metrics']['profit_after_ads'], $previous['metrics']['profit_after_ads'])
                    : null,
                'ad_spend_percent' => $current['metrics']['ad_spend'] !== null && $previous['metrics']['ad_spend'] !== null
                    ? $this->change($current['metrics']['ad_spend'], $previous['metrics']['ad_spend'])
                    : null,
                'previous_profit_after_ads' => $previous['metrics']['profit_after_ads'],
                'previous_revenue' => $previous['metrics']['revenue'],
            ],
            'trend' => $this->trend($current['orders'], $from, $to),
            'products' => array_values($current['products']),
            'stores' => array_values($current['stores']),
            'generated_at' => now('Asia/Jakarta')->toIso8601String(),
        ];
    }

    private function aggregate(Collection $stores, Carbon $from, Carbon $to, bool $withDetails): array
    {
        $metrics = [
            'revenue' => 0,
            'qty_sold' => 0,
            'orders_included' => 0,
            'orders_actual' => 0,
            'orders_estimated' => 0,
            'orders_missing_hpp' => 0,
            'orders_missing_fee_config' => 0,
            'profit_before_ads_hybrid' => 0,
            'ad_spend' => 0,
            'ad_spend_known' => 0,
            'ad_spend_precise' => true,
            'profit_after_ads' => null,
            'actual_order_percent' => null,
        ];
        $products = [];
        $storeRows = [];
        $allOrders = collect();
        $hasAnyData = false;

        foreach ($stores as $store) {
            $store->loadMissing('feeHistories');
            $orders = Order::with([
                'items.variant.costHistories',
                'items.variant.product.costHistories',
                'items.product.costHistories',
                'items.product.variants.costHistories',
                'items.product.variants.product.costHistories',
                'settlement',
                'adjustments',
            ])
                ->where('store_id', $store->id)
                ->whereBetween('ordered_at', [$from, $to])
                ->orderBy('ordered_at')
                ->get();

            $ad = $this->ads->resolve($store, $from->toDateString(), $to->toDateString());
            $storeMetric = [
                'revenue' => 0,
                'qty_sold' => 0,
                'orders_included' => 0,
                'orders_actual' => 0,
                'orders_estimated' => 0,
                'orders_missing_hpp' => 0,
                'orders_missing_fee_config' => 0,
                'profit_before_ads_hybrid' => 0,
                'ad_spend' => $ad['amount'],
                'ad_spend_known' => $ad['known_amount'],
                'ad_spend_precise' => $ad['precise'],
                'profit_after_ads' => null,
            ];

            foreach ($orders as $order) {
                if (!$this->included($order->status)) {
                    continue;
                }

                $hasAnyData = true;
                $allOrders->push($order);
                $revenue = (int) $order->product_revenue;
                $qty = (int) $order->total_qty;
                $metrics['revenue'] += $revenue;
                $metrics['qty_sold'] += $qty;
                $metrics['orders_included']++;
                $storeMetric['revenue'] += $revenue;
                $storeMetric['qty_sold'] += $qty;
                $storeMetric['orders_included']++;

                $fee = $this->costs->storeFee($store, $order->ordered_at ?? $from);
                $estimatedAdmin = 0;
                $hpp = 0;
                $completeHpp = true;

                foreach ($order->items as $item) {
                    $cost = $this->costs->item($item, $order->ordered_at ?? $from);
                    $admin = $cost['admin_percent'] ?? $fee['default_admin_percent'];
                    $estimatedAdmin += (int) round((int) $item->subtotal * ((float) $admin / 100));
                    if ($cost['hpp'] === null) {
                        $completeHpp = false;
                    } else {
                        $hpp += (int) $cost['hpp'] * (int) $item->qty;
                    }

                    if ($withDetails) {
                        $name = trim((string) ($item->product?->name ?: $item->product_name ?: 'Produk tidak dikenal'));
                        $key = mb_strtolower(preg_replace('/\s+/', ' ', $name));
                        if (!isset($products[$key])) {
                            $products[$key] = ['product_name' => $name, 'qty' => 0, 'revenue' => 0, 'stores_count' => 0, '_stores' => []];
                        }
                        $products[$key]['qty'] += (int) $item->qty;
                        $products[$key]['revenue'] += (int) $item->subtotal;
                        $products[$key]['_stores'][$store->id] = true;
                    }
                }

                // HPP benar-benar dipisahkan dari konfigurasi fee.
                // Produk arsip yang tidak punya order di periode ini tidak pernah masuk loop ini,
                // sehingga tidak dapat memicu warning HPP.
                if (!$completeHpp) {
                    $metrics['orders_missing_hpp']++;
                    $storeMetric['orders_missing_hpp']++;
                    continue;
                }

                if ($order->settlement) {
                    // Kalau Penghasilan Shopee aktual sudah ada, fee estimasi tidak diperlukan lagi.
                    // actual_income sudah net dari biaya platform Shopee.
                    $adjust = (int) $order->adjustments->sum('amount');
                    $profit = (int) $order->settlement->actual_income + $adjust - $hpp;
                    $metrics['orders_actual']++;
                    $storeMetric['orders_actual']++;
                } else {
                    // Fee/admin hanya dibutuhkan untuk order yang belum settle karena profitnya masih estimasi.
                    if (!$fee['configured']) {
                        $metrics['orders_missing_fee_config']++;
                        $storeMetric['orders_missing_fee_config']++;
                        continue;
                    }
                    $expectedSettlement = $revenue - $estimatedAdmin - (int) $fee['fixed_fee_per_order'];
                    $profit = $expectedSettlement - $hpp;
                    $metrics['orders_estimated']++;
                    $storeMetric['orders_estimated']++;
                }

                $metrics['profit_before_ads_hybrid'] += $profit;
                $storeMetric['profit_before_ads_hybrid'] += $profit;
            }

            if (($ad['known_amount'] ?? 0) > 0) {
                $hasAnyData = true;
            }
            $metrics['ad_spend_known'] += (int) ($ad['known_amount'] ?? 0);
            if ($ad['precise']) {
                $metrics['ad_spend'] += (int) $ad['amount'];
                $storeMetric['profit_after_ads'] = $storeMetric['profit_before_ads_hybrid'] - (int) $ad['amount'];
            } else {
                $metrics['ad_spend_precise'] = false;
            }

            if ($withDetails && ($storeMetric['orders_included'] > 0 || ($ad['known_amount'] ?? 0) > 0)) {
                $storeRows[] = [
                    'store_id' => $store->id,
                    'store_name' => $store->name,
                    ...$storeMetric,
                ];
            }
        }

        foreach ($products as &$row) {
            $row['stores_count'] = count($row['_stores']);
            unset($row['_stores']);
        }
        unset($row);
        uasort($products, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);
        usort($storeRows, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        if ($metrics['ad_spend_precise']) {
            $metrics['profit_after_ads'] = $metrics['profit_before_ads_hybrid'] - $metrics['ad_spend'];
        } else {
            $metrics['ad_spend'] = null;
        }
        $estimableOrders = $metrics['orders_actual'] + $metrics['orders_estimated'];
        $metrics['actual_order_percent'] = $estimableOrders > 0
            ? round(($metrics['orders_actual'] / $estimableOrders) * 100, 1)
            : null;

        return [
            'has_data' => $hasAnyData,
            'metrics' => $metrics,
            'products' => $products,
            'stores' => $storeRows,
            'orders' => $allOrders,
        ];
    }

    private function trend(Collection $orders, Carbon $from, Carbon $to): array
    {
        $days = $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        $daily = $days <= 14;
        $rows = [];
        $cursor = $from->copy()->startOfDay();

        while ($cursor->lte($to)) {
            $bucketStart = $cursor->copy();
            $bucketEnd = $daily
                ? $cursor->copy()->endOfDay()
                : $cursor->copy()->addDays(6)->endOfDay();
            if ($bucketEnd->gt($to)) {
                $bucketEnd = $to->copy();
            }

            $revenue = 0;
            $qty = 0;
            foreach ($orders as $order) {
                $date = $order->ordered_at;
                if ($date && $date->between($bucketStart, $bucketEnd, true)) {
                    $revenue += (int) $order->product_revenue;
                    $qty += (int) $order->total_qty;
                }
            }

            $rows[] = [
                'start' => $bucketStart->toDateString(),
                'end' => $bucketEnd->toDateString(),
                'label' => $daily
                    ? $bucketStart->format('d/m')
                    : $bucketStart->format('d/m') . '–' . $bucketEnd->format('d/m'),
                'revenue' => $revenue,
                'qty' => $qty,
            ];
            $cursor = $bucketEnd->copy()->addDay()->startOfDay();
        }

        return [
            'granularity' => $daily ? 'day' : 'week',
            'metric' => 'revenue',
            'rows' => $rows,
        ];
    }

    private function included(?string $status): bool
    {
        $s = mb_strtolower(trim((string) $status));
        if ($s === '' || str_contains($s, 'batal')) {
            return false;
        }
        return str_contains($s, 'selesai') || str_contains($s, 'dikirim');
    }

    private function change(int|float $current, int|float $previous): ?float
    {
        if ((float) $previous === 0.0) {
            return (float) $current === 0.0 ? 0.0 : null;
        }
        return round((($current - $previous) / abs($previous)) * 100, 1);
    }
}
