<?php
namespace App\Services\Imports;

use App\Models\AdCostPeriod;
use App\Models\Store;
use App\Services\Reports\ClosingStaleService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class AdsImporter
{
    public function __construct(
        private ImportSupport $support,
        private ImportBackupService $backups,
        private ShopeeAdsCsvReader $reader,
        private ClosingStaleService $closings,
    ) {}

    public function import(Store $store, UploadedFile $file, int $userId, bool $replace = false): array
    {
        $batch = $this->support->begin($store->id, $userId, 'ads', $file);
        try {
            $parsed = $this->reader->read($file->getRealPath());
            $this->assertStore($store, $parsed);
            $start = $parsed['start_date'];
            $end = $parsed['end_date'];
            $overlaps = $this->overlaps($store->id, $start, $end);

            $exactOnly = $overlaps->count() === 1
                && $overlaps->first()->start_date->toDateString() === $start
                && $overlaps->first()->end_date->toDateString() === $end;

            if ($overlaps->isNotEmpty() && !$exactOnly && !$replace) {
                throw new \RuntimeException('Periode CSV iklan bertumpang tindih dengan data iklan yang sudah ada. Gunakan replace hanya jika file baru memang menggantikan periode lama.');
            }
            if ($replace) $this->assertSafeReplacement($overlaps, $start, $end);

            $this->backups->save($batch, [
                'affected_start_date' => $start,
                'affected_end_date' => $end,
                'periods_before' => $this->backups->attrs($overlaps),
            ]);

            DB::transaction(function () use ($store, $file, $userId, $batch, $parsed, $overlaps, $exactOnly, $replace) {
                if ($replace || $exactOnly) {
                    AdCostPeriod::whereIn('id', $overlaps->pluck('id'))->delete();
                }

                AdCostPeriod::create([
                    'store_id' => $store->id,
                    'start_date' => $parsed['start_date'],
                    'end_date' => $parsed['end_date'],
                    'amount' => $parsed['amount'],
                    'source' => 'shopee_csv',
                    'source_filename' => $file->getClientOriginalName(),
                    'source_hash' => hash_file('sha256', $file->getRealPath()),
                    'shopee_username' => $parsed['username'],
                    'shopee_shop_id' => $parsed['shop_id'],
                    'breakdown' => [
                        'rows' => $parsed['rows'],
                        'reported_gmv' => $parsed['reported_gmv'],
                        'product_spend' => $parsed['product_breakdown'],
                    ],
                    'note' => 'Import laporan iklan Shopee',
                    'updated_by' => $userId,
                    'last_import_batch_id' => $batch->id,
                ]);

                if (!$store->shopee_username && $parsed['username']) $store->shopee_username = $parsed['username'];
                if (!$store->shopee_shop_id && $parsed['shop_id']) $store->shopee_shop_id = $parsed['shop_id'];
                if ($store->isDirty()) $store->save();
            });

            $batch->update([
                'source_start_date' => $start,
                'source_end_date' => $end,
                'rows_read' => $parsed['rows'],
                'created_count' => 1,
                'updated_count' => $overlaps->count(),
            ]);
            $this->closings->range($store->id, $start, $end);
            $summary = [
                'ads_periods' => 1,
                'rows' => $parsed['rows'],
                'amount' => $parsed['amount'],
                'reported_gmv' => $parsed['reported_gmv'],
                'products_with_ads' => count(array_filter($parsed['product_breakdown'], fn($x) => !empty($x['shopee_product_id']))),
                'unallocated_amount' => collect($parsed['product_breakdown'])->whereNull('shopee_product_id')->sum('amount'),
                'period' => [$start, $end],
            ];
            $this->support->finish($batch, $summary);
            return ['batch' => $batch->fresh(), 'summary' => $summary];
        } catch (\Throwable $e) {
            $this->support->fail($batch, $e);
            throw $e;
        }
    }

    private function assertStore(Store $store, array $parsed): void
    {
        if ($store->shopee_username && $parsed['username'] && strcasecmp($store->shopee_username, $parsed['username']) !== 0) {
            throw new \RuntimeException("File iklan milik seller {$parsed['username']}, sedangkan toko terpilih {$store->shopee_username}.");
        }
        if ($store->shopee_shop_id && $parsed['shop_id'] && (string)$store->shopee_shop_id !== (string)$parsed['shop_id']) {
            throw new \RuntimeException("ID toko pada file iklan ({$parsed['shop_id']}) berbeda dengan toko terpilih ({$store->shopee_shop_id}).");
        }
    }

    private function overlaps(int $storeId, string $start, string $end)
    {
        return AdCostPeriod::where('store_id', $storeId)
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->orderBy('start_date')
            ->get();
    }

    private function assertSafeReplacement($overlaps, string $start, string $end): void
    {
        foreach ($overlaps as $row) {
            if ($row->start_date->toDateString() < $start || $row->end_date->toDateString() > $end) {
                throw new \RuntimeException('Replace ditolak: file baru hanya menutupi sebagian periode iklan lama. Hapus periode lama secara manual atau upload range yang mencakupnya penuh agar sisa biaya tidak hilang.');
            }
        }
    }
}
