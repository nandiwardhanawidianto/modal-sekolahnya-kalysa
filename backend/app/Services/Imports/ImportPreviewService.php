<?php
namespace App\Services\Imports;

use App\Models\AdCostPeriod;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;

final class ImportPreviewService
{
    public function __construct(private ShopeeAdsCsvReader $adsReader) {}

    public function preview(Store $store, string $type, UploadedFile $file): array
    {
        return match ($type) {
            'products' => $this->products($file),
            'orders' => $this->orders($file),
            'income' => $this->income($store, $file),
            'ads' => $this->ads($store, $file),
            default => throw new \InvalidArgumentException('Jenis import tidak dikenal.'),
        };
    }

    private function products(UploadedFile $file): array
    {
        $r = new XlsxReader($file->getRealPath());
        $sheet = $r->sheetNames()[0] ?? throw new \RuntimeException('Workbook kosong.');
        $header = $r->findHeader($sheet, ['Kode Produk','Kode Variasi','Nama Produk','Nama Variasi']);
        $h = $header['map']; $variants = 0; $products = [];
        foreach ($r->rows($sheet) as $row) {
            if ($row['row'] <= $header['row']) continue;
            $v = $row['values']; $pid = trim($v[$h['Kode Produk']] ?? ''); $vid = trim($v[$h['Kode Variasi']] ?? '');
            if ($pid === '' || $vid === '' || !ctype_digit($pid)) continue;
            $variants++; $products[$pid] = true;
        }
        return ['type'=>'products','products'=>count($products),'variants'=>$variants];
    }

    private function orders(UploadedFile $file): array
    {
        $r = new XlsxReader($file->getRealPath());
        $sheet = in_array('orders', $r->sheetNames(), true) ? 'orders' : ($r->sheetNames()[0] ?? throw new \RuntimeException('Workbook kosong.'));
        $header = $r->findHeader($sheet, ['No. Pesanan','Status Pesanan','Waktu Pesanan Dibuat','Jumlah','Subtotal Pesanan']);
        $h = $header['map']; $orders = []; $rows = 0; $status = []; $min = null; $max = null;
        foreach ($r->rows($sheet) as $row) {
            if ($row['row'] <= $header['row']) continue;
            $v = $row['values']; $no = trim($v[$h['No. Pesanan']] ?? ''); if ($no === '') continue;
            $rows++;
            if (!isset($orders[$no])) {
                $orders[$no] = true; $s = trim($v[$h['Status Pesanan']] ?? '') ?: 'Kosong'; $status[$s] = ($status[$s] ?? 0) + 1;
            }
            $d = $this->date($v[$h['Waktu Pesanan Dibuat']] ?? null);
            if ($d) { $min = $min === null || $d < $min ? $d : $min; $max = $max === null || $d > $max ? $d : $max; }
        }
        [$fs,$fe] = ImportSupport::dateRangeFromFilename($file->getClientOriginalName());
        return ['type'=>'orders','orders'=>count($orders),'item_rows'=>$rows,'status'=>$status,'period'=>[$fs ?: $min,$fe ?: $max]];
    }

    private function income(Store $store, UploadedFile $file): array
    {
        $r = new XlsxReader($file->getRealPath());
        $username = $this->summaryValue($r, 'Username (Penjual)');
        if ($store->shopee_username && $username && strcasecmp($store->shopee_username, $username) !== 0) {
            throw new \RuntimeException("File ini milik seller {$username}, sedangkan toko terpilih {$store->shopee_username}.");
        }
        $start = $this->summaryValue($r, 'Dari'); $end = $this->summaryValue($r, 'ke'); $settlements = 0; $adjustments = 0;
        foreach ($r->sheetNames() as $sheet) {
            if (!str_starts_with($sheet, 'Penghasilan -')) continue;
            $header = $r->findHeader($sheet, ['Lihat berdasarkan','No. Pesanan','Total Penghasilan']); $h = $header['map'];
            foreach ($r->rows($sheet) as $row) {
                if ($row['row'] <= $header['row']) continue;
                $v = $row['values'];
                if (strcasecmp(trim($v[$h['Lihat berdasarkan']] ?? ''), 'Order') === 0 && trim($v[$h['No. Pesanan']] ?? '') !== '') $settlements++;
            }
        }
        if (in_array('Adjustment', $r->sheetNames(), true)) {
            $header = $r->findHeader('Adjustment', ['Tanggal Penyesuaian Dibuat','Tipe Penyesuaian | Deskripsi','Biaya Penyesuaian']); $h = $header['map'];
            foreach ($r->rows('Adjustment') as $row) {
                if ($row['row'] <= $header['row']) continue;
                $v = $row['values'];
                if (trim($v[$h['Tanggal Penyesuaian Dibuat']] ?? '') !== '' && trim($v[$h['Tipe Penyesuaian | Deskripsi']] ?? '') !== '') $adjustments++;
            }
        }
        return ['type'=>'income','seller_username'=>$username,'period'=>[$start,$end],'settlements'=>$settlements,'adjustments'=>$adjustments];
    }

    private function ads(Store $store, UploadedFile $file): array
    {
        $x = $this->adsReader->read($file->getRealPath());
        if ($store->shopee_username && $x['username'] && strcasecmp($store->shopee_username, $x['username']) !== 0) {
            throw new \RuntimeException("File iklan milik seller {$x['username']}, sedangkan toko terpilih {$store->shopee_username}.");
        }
        if ($store->shopee_shop_id && $x['shop_id'] && (string)$store->shopee_shop_id !== (string)$x['shop_id']) {
            throw new \RuntimeException("ID toko pada file iklan ({$x['shop_id']}) berbeda dengan toko terpilih ({$store->shopee_shop_id}).");
        }
        $overlaps = AdCostPeriod::where('store_id', $store->id)
            ->whereDate('start_date', '<=', $x['end_date'])
            ->whereDate('end_date', '>=', $x['start_date'])
            ->orderBy('start_date')->get();
        return [
            'type' => 'ads',
            'seller_username' => $x['username'],
            'shop_name' => $x['shop_name'],
            'shop_id' => $x['shop_id'],
            'period' => [$x['start_date'], $x['end_date']],
            'rows' => $x['rows'],
            'total_cost' => $x['amount'],
            'reported_gmv' => $x['reported_gmv'],
            'products_with_ads' => count(array_filter($x['product_breakdown'], fn($p) => !empty($p['shopee_product_id']))),
            'unallocated_cost' => collect($x['product_breakdown'])->whereNull('shopee_product_id')->sum('amount'),
            'overlaps' => $overlaps->map(fn($p) => [
                'id'=>$p->id,'start_date'=>$p->start_date->toDateString(),'end_date'=>$p->end_date->toDateString(),'amount'=>(int)$p->amount,'source'=>$p->source,
            ])->values(),
        ];
    }

    private function summaryValue(XlsxReader $r, string $label): ?string
    {
        if (!in_array('Summary', $r->sheetNames(), true)) return null;
        foreach ($r->rows('Summary') as $row) {
            foreach ($row['values'] as $i => $v) if (trim($v) === $label) {
                $n = trim($row['values'][$i+1] ?? ''); return $n !== '' ? $n : null;
            }
            if ($row['row'] > 20) break;
        }
        return null;
    }

    private function date(mixed $value): ?string
    {
        if (!$value || $value === '-') return null;
        try { return Carbon::parse((string)$value)->toDateString(); } catch (\Throwable) { return null; }
    }
}
