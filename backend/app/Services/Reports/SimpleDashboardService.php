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

        $storeRows = $this->enrichStoreRows(
            $current['stores'],
            $previous['stores'],
            $current['metrics']['profit_after_ads']
        );

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
            'trend' => $this->trend($current['trend_inputs'], $current['ads_by_store'], $stores, $from, $to),
            'products' => array_values($current['products']),
            'stores' => $storeRows,
            'attention' => $this->attention($storeRows),
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
        $trendInputs = [];
        $adsByStore = [];
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
            $adsByStore[$store->id] = $ad;
            $trendInputs[$store->id] = [];

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
                'margin_before_ads_percent' => null,
                'margin_after_ads_percent' => null,
            ];

            foreach ($orders as $order) {
                if (!$this->included($order->status)) {
                    continue;
                }

                $hasAnyData = true;
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

                $profit = null;
                $profitSource = null;

                if (!$completeHpp) {
                    $metrics['orders_missing_hpp']++;
                    $storeMetric['orders_missing_hpp']++;
                } elseif ($order->settlement) {
                    // Penghasilan Shopee sudah net biaya platform, jadi fee estimasi tidak dibutuhkan.
                    $adjust = (int) $order->adjustments->sum('amount');
                    $profit = (int) $order->settlement->actual_income + $adjust - $hpp;
                    $profitSource = 'actual';
                    $metrics['orders_actual']++;
                    $storeMetric['orders_actual']++;
                } elseif (!$fee['configured']) {
                    $metrics['orders_missing_fee_config']++;
                    $storeMetric['orders_missing_fee_config']++;
                } else {
                    $expectedSettlement = $revenue - $estimatedAdmin - (int) $fee['fixed_fee_per_order'];
                    $profit = $expectedSettlement - $hpp;
                    $profitSource = 'estimated';
                    $metrics['orders_estimated']++;
                    $storeMetric['orders_estimated']++;
                }

                if ($profit !== null) {
                    $metrics['profit_before_ads_hybrid'] += $profit;
                    $storeMetric['profit_before_ads_hybrid'] += $profit;
                }

                if ($withDetails) {
                    $trendInputs[$store->id][] = [
                        'ordered_at' => $order->ordered_at?->copy(),
                        'revenue' => $revenue,
                        'qty' => $qty,
                        'profit_before_ads' => $profit,
                        'profit_source' => $profitSource,
                    ];
                }
            }

            if (($ad['known_amount'] ?? 0) > 0) {
                $hasAnyData = true;
            }
            $storeHasData = $storeMetric['orders_included'] > 0 || ($ad['known_amount'] ?? 0) > 0;
            if ($storeHasData) {
                $metrics['ad_spend_known'] += (int) ($ad['known_amount'] ?? 0);
                if ($ad['precise']) {
                    $metrics['ad_spend'] += (int) $ad['amount'];
                    $storeMetric['profit_after_ads'] = $storeMetric['profit_before_ads_hybrid'] - (int) $ad['amount'];
                } else {
                    $metrics['ad_spend_precise'] = false;
                }
            }

            if ($storeMetric['revenue'] > 0) {
                $storeMetric['margin_before_ads_percent'] = round(($storeMetric['profit_before_ads_hybrid'] / $storeMetric['revenue']) * 100, 2);
                if ($storeMetric['profit_after_ads'] !== null) {
                    $storeMetric['margin_after_ads_percent'] = round(($storeMetric['profit_after_ads'] / $storeMetric['revenue']) * 100, 2);
                }
            }

            if ($storeMetric['orders_included'] > 0 || ($ad['known_amount'] ?? 0) > 0) {
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
            'trend_inputs' => $trendInputs,
            'ads_by_store' => $adsByStore,
        ];
    }

    private function trend(array $inputsByStore, array $adsByStore, Collection $stores, Carbon $from, Carbon $to): array
    {
        $days = $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        $daily = $days <= 14;
        $buckets = [];
        $cursor = $from->copy()->startOfDay();

        while ($cursor->lte($to)) {
            $bucketStart = $cursor->copy();
            $bucketEnd = $daily
                ? $cursor->copy()->endOfDay()
                : $cursor->copy()->addDays(6)->endOfDay();
            if ($bucketEnd->gt($to)) {
                $bucketEnd = $to->copy();
            }
            $buckets[] = [
                'start' => $bucketStart,
                'end' => $bucketEnd,
                'label' => $daily
                    ? $bucketStart->format('d/m')
                    : $bucketStart->format('d/m') . '–' . $bucketEnd->format('d/m'),
            ];
            $cursor = $bucketEnd->copy()->addDay()->startOfDay();
        }

        $series = [];
        foreach ($stores as $store) {
            $inputs = collect($inputsByStore[$store->id] ?? []);
            $points = [];
            $allProfitAfterAdsPrecise = true;
            $hasPointData = false;

            foreach ($buckets as $bucket) {
                $rows = $inputs->filter(function ($row) use ($bucket) {
                    $date = $row['ordered_at'] ?? null;
                    return $date && $date->between($bucket['start'], $bucket['end'], true);
                });

                $revenue = (int) $rows->sum('revenue');
                $qty = (int) $rows->sum('qty');
                $profitComplete = !$rows->contains(fn ($row) => $row['profit_before_ads'] === null);
                $profitBeforeAds = $profitComplete ? (int) $rows->sum('profit_before_ads') : null;
                $bucketAd = $this->bucketAd($adsByStore[$store->id] ?? null, $bucket['start'], $bucket['end']);
                $profitAfterAds = $profitComplete && $bucketAd['precise'] && $profitBeforeAds !== null
                    ? $profitBeforeAds - $bucketAd['amount']
                    : null;

                if ($revenue > 0 || $qty > 0 || ($bucketAd['known_amount'] ?? 0) > 0) {
                    $hasPointData = true;
                }
                if (!$bucketAd['precise']) {
                    $allProfitAfterAdsPrecise = false;
                }

                $points[] = [
                    'start' => $bucket['start']->toDateString(),
                    'end' => $bucket['end']->toDateString(),
                    'label' => $bucket['label'],
                    'revenue' => $revenue,
                    'qty' => $qty,
                    'profit_before_ads' => $profitBeforeAds,
                    'profit_after_ads' => $profitAfterAds,
                    'ad_spend' => $bucketAd['precise'] ? $bucketAd['amount'] : null,
                    'ad_spend_known' => $bucketAd['known_amount'],
                    'ad_spend_precise' => $bucketAd['precise'],
                    'margin_before_ads_percent' => $revenue > 0 && $profitBeforeAds !== null
                        ? round(($profitBeforeAds / $revenue) * 100, 2)
                        : null,
                    'margin_after_ads_percent' => $revenue > 0 && $profitAfterAds !== null
                        ? round(($profitAfterAds / $revenue) * 100, 2)
                        : null,
                ];
            }

            if ($hasPointData) {
                $series[] = [
                    'store_id' => $store->id,
                    'store_name' => $store->name,
                    'profit_after_ads_bucket_precise' => $allProfitAfterAdsPrecise,
                    'points' => $points,
                ];
            }
        }

        return [
            'granularity' => $daily ? 'day' : 'week',
            'buckets' => array_map(fn ($bucket) => [
                'start' => $bucket['start']->toDateString(),
                'end' => $bucket['end']->toDateString(),
                'label' => $bucket['label'],
            ], $buckets),
            'series' => $series,
            'profit_note' => 'Profit total per toko tetap memakai profit setelah iklan bila iklan periode lengkap. Grafik profit memakai setelah iklan hanya bila biaya iklan juga presisi untuk setiap bucket; jika tidak, grafik memakai profit sebelum iklan dan tidak membagi iklan bulanan secara tebakan.',
        ];
    }

    private function bucketAd(?array $ad, Carbon $from, Carbon $to): array
    {
        if (!$ad) {
            return ['precise' => false, 'amount' => null, 'known_amount' => 0];
        }

        $covered = [];
        $known = 0;
        $partial = false;
        foreach ($ad['usable_periods'] ?? [] as $period) {
            $start = Carbon::parse($period['start_date'], 'Asia/Jakarta')->startOfDay();
            $end = Carbon::parse($period['end_date'], 'Asia/Jakarta')->endOfDay();
            if ($end->lt($from) || $start->gt($to)) {
                continue;
            }
            if ($start->lt($from) || $end->gt($to)) {
                $partial = true;
                continue;
            }
            $known += (int) $period['amount'];
            $d = $start->copy()->startOfDay();
            while ($d->lte($end)) {
                $covered[$d->toDateString()] = true;
                $d->addDay();
            }
        }

        // Periode yang pada laporan utama dianggap partial juga tetap membuat bucket tidak presisi.
        foreach ($ad['partial_periods'] ?? [] as $period) {
            $start = Carbon::parse($period['start_date'], 'Asia/Jakarta')->startOfDay();
            $end = Carbon::parse($period['end_date'], 'Asia/Jakarta')->endOfDay();
            if (!$end->lt($from) && !$start->gt($to)) {
                $partial = true;
            }
        }

        $required = $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        $precise = !$partial && count($covered) === $required;
        return ['precise' => $precise, 'amount' => $precise ? $known : null, 'known_amount' => $known];
    }

    private function enrichStoreRows(array $currentRows, array $previousRows, ?int $totalProfitAfterAds): array
    {
        $previousMap = collect($previousRows)->keyBy('store_id');
        $rows = [];

        foreach ($currentRows as $row) {
            $previous = $previousMap->get($row['store_id']);
            $previousRevenue = (int) ($previous['revenue'] ?? 0);
            $previousQty = (int) ($previous['qty_sold'] ?? 0);
            $previousProfit = $previous['profit_after_ads'] ?? null;
            $previousAds = $previous['ad_spend'] ?? null;
            $previousMargin = $previous['margin_after_ads_percent'] ?? null;

            $row['previous_revenue'] = $previousRevenue;
            $row['previous_qty_sold'] = $previousQty;
            $row['previous_profit_after_ads'] = $previousProfit;
            $row['previous_ad_spend'] = $previousAds;
            $row['previous_margin_after_ads_percent'] = $previousMargin;
            $row['revenue_change_percent'] = $this->change($row['revenue'], $previousRevenue);
            $row['qty_change_percent'] = $this->change($row['qty_sold'], $previousQty);
            $row['profit_change_percent'] = $row['profit_after_ads'] !== null && $previousProfit !== null
                ? $this->change($row['profit_after_ads'], $previousProfit)
                : null;
            $row['ad_spend_change_percent'] = $row['ad_spend'] !== null && $previousAds !== null
                ? $this->change($row['ad_spend'], $previousAds)
                : null;
            $row['margin_change_points'] = $row['margin_after_ads_percent'] !== null && $previousMargin !== null
                ? round($row['margin_after_ads_percent'] - $previousMargin, 2)
                : null;
            $row['profit_contribution_percent'] = $totalProfitAfterAds !== null
                && $totalProfitAfterAds != 0
                && $row['profit_after_ads'] !== null
                ? round(($row['profit_after_ads'] / $totalProfitAfterAds) * 100, 1)
                : null;
            $rows[] = $row;
        }

        usort($rows, function ($a, $b) {
            if ($a['profit_after_ads'] !== null && $b['profit_after_ads'] !== null) {
                return $b['profit_after_ads'] <=> $a['profit_after_ads'];
            }
            if ($a['profit_after_ads'] !== null) return -1;
            if ($b['profit_after_ads'] !== null) return 1;
            return $b['revenue'] <=> $a['revenue'];
        });

        return $rows;
    }

    private function attention(array $stores): array
    {
        $alerts = [];
        foreach ($stores as $store) {
            $base = ['store_id' => $store['store_id'], 'store_name' => $store['store_name']];

            if (($store['orders_missing_hpp'] ?? 0) > 0) {
                $alerts[] = [...$base, 'severity' => 100, 'type' => 'hpp', 'message' => $store['orders_missing_hpp'] . ' order belum punya HPP lengkap.'];
            }
            if (($store['orders_missing_fee_config'] ?? 0) > 0) {
                $alerts[] = [...$base, 'severity' => 90, 'type' => 'fee', 'message' => $store['orders_missing_fee_config'] . ' order belum settle belum bisa diestimasi karena fee/admin belum lengkap.'];
            }
            if (!($store['ad_spend_precise'] ?? false)) {
                $alerts[] = [...$base, 'severity' => 80, 'type' => 'ads', 'message' => 'Biaya iklan periode belum lengkap, jadi profit setelah iklan belum final.'];
            }
            if (($store['profit_change_percent'] ?? null) !== null && $store['profit_change_percent'] <= -10) {
                $alerts[] = [...$base, 'severity' => 70, 'type' => 'profit_down', 'message' => 'Profit turun ' . abs($store['profit_change_percent']) . '% dibanding periode sebelumnya.'];
            }
            if (($store['margin_change_points'] ?? null) !== null && $store['margin_change_points'] <= -5) {
                $alerts[] = [...$base, 'severity' => 60, 'type' => 'margin_down', 'message' => 'Margin turun ' . abs($store['margin_change_points']) . ' poin dibanding periode sebelumnya.'];
            }
            if (($store['revenue_change_percent'] ?? null) !== null && $store['revenue_change_percent'] <= -15) {
                $alerts[] = [...$base, 'severity' => 50, 'type' => 'revenue_down', 'message' => 'Omzet turun ' . abs($store['revenue_change_percent']) . '% dibanding periode sebelumnya.'];
            }
            $adGrowth = $store['ad_spend_change_percent'] ?? null;
            $revenueGrowth = $store['revenue_change_percent'] ?? null;
            if ($adGrowth !== null && $adGrowth >= 20 && ($revenueGrowth === null || $adGrowth >= $revenueGrowth + 10)) {
                $alerts[] = [...$base, 'severity' => 40, 'type' => 'ads_efficiency', 'message' => 'Biaya iklan naik ' . $adGrowth . '% lebih cepat daripada pertumbuhan omzet.'];
            }
        }

        usort($alerts, fn ($a, $b) => $b['severity'] <=> $a['severity']);
        return array_slice($alerts, 0, 10);
    }

    private function included(?string $status): bool
    {
        $s = mb_strtolower(trim((string) $status));
        if ($s === '' || str_contains($s, 'batal') || str_contains($s, 'perlu dikirim')) {
            return false;
        }
        return str_contains($s, 'selesai') || str_contains($s, 'sedang dikirim') || str_contains($s, 'telah dikirim');
    }

    private function change(int|float $current, int|float $previous): ?float
    {
        if ((float) $previous === 0.0) {
            return (float) $current === 0.0 ? 0.0 : null;
        }
        return round((($current - $previous) / abs($previous)) * 100, 1);
    }
}
