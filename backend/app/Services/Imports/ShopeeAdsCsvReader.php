<?php
namespace App\Services\Imports;

final class ShopeeAdsCsvReader
{
    public function read(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (!$handle) throw new \RuntimeException('File CSV iklan tidak bisa dibuka.');

        $meta = [];
        $header = null;
        $rows = 0;
        $total = 0;
        $totalGmv = 0;
        $productSpend = [];

        try {
            while (($cols = fgetcsv($handle)) !== false) {
                if (!$cols) continue;
                $cols[0] = $this->clean((string)($cols[0] ?? ''));
                $first = trim($cols[0]);

                if ($header === null) {
                    if ($first === 'Urutan' && in_array('Biaya', $cols, true)) {
                        $header = array_flip(array_map(fn($v) => trim($this->clean((string)$v)), $cols));
                        foreach (['Kode Produk','Biaya'] as $required) {
                            if (!array_key_exists($required, $header)) throw new \RuntimeException("Kolom {$required} tidak ditemukan di CSV iklan.");
                        }
                        continue;
                    }
                    if ($first !== '' && isset($cols[1]) && trim((string)$cols[1]) !== '') {
                        $meta[$first] = trim($this->clean((string)$cols[1]));
                    }
                    continue;
                }

                $order = trim((string)($cols[$header['Urutan']] ?? ''));
                if ($order === '' || !ctype_digit($order)) continue;
                $rows++;

                $cost = $this->integer($cols[$header['Biaya']] ?? 0);
                $gmv = isset($header['Omzet Penjualan']) ? $this->integer($cols[$header['Omzet Penjualan']] ?? 0) : 0;
                $productId = trim((string)($cols[$header['Kode Produk']] ?? ''));
                $productId = ($productId === '' || $productId === '-') ? null : $productId;
                $adName = isset($header['Nama Iklan']) ? trim((string)($cols[$header['Nama Iklan']] ?? '')) : null;

                $total += $cost;
                $totalGmv += $gmv;
                $key = $productId ?: '__unallocated__';
                if (!isset($productSpend[$key])) {
                    $productSpend[$key] = [
                        'shopee_product_id' => $productId,
                        'amount' => 0,
                        'reported_gmv' => 0,
                        'ad_rows' => 0,
                        'sample_ad_name' => $adName ?: null,
                    ];
                }
                $productSpend[$key]['amount'] += $cost;
                $productSpend[$key]['reported_gmv'] += $gmv;
                $productSpend[$key]['ad_rows']++;
            }
        } finally {
            fclose($handle);
        }

        if ($header === null) throw new \RuntimeException('Header laporan iklan Shopee tidak ditemukan.');
        [$start, $end] = $this->period($meta['Periode'] ?? null);
        if (!$start || !$end) throw new \RuntimeException('Periode laporan iklan tidak ditemukan atau formatnya tidak dikenali.');

        return [
            'username' => $meta['Username'] ?? null,
            'shop_name' => $meta['Nama Toko'] ?? null,
            'shop_id' => $meta['ID Toko'] ?? null,
            'report_created_at' => $meta['Waktu Laporan Dibuat'] ?? null,
            'start_date' => $start,
            'end_date' => $end,
            'rows' => $rows,
            'amount' => $total,
            'reported_gmv' => $totalGmv,
            'product_breakdown' => array_values($productSpend),
        ];
    }

    private function clean(string $value): string
    {
        return preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
    }

    private function integer(mixed $value): int
    {
        if ($value === null || $value === '' || $value === '-') return 0;
        $s = trim((string)$value);
        if (preg_match('/^-?\d+(?:\.0+)?$/', $s)) return (int)round((float)$s);
        $negative = str_starts_with($s, '-');
        $digits = preg_replace('/[^0-9]/', '', $s) ?: '0';
        return ($negative ? -1 : 1) * (int)$digits;
    }

    private function period(?string $value): array
    {
        if (!$value || !preg_match('/(\d{2}\/\d{2}\/\d{4})\s*-\s*(\d{2}\/\d{2}\/\d{4})/', $value, $m)) return [null, null];
        try {
            return [
                (\DateTimeImmutable::createFromFormat('!d/m/Y', $m[1]) ?: throw new \RuntimeException('Tanggal awal iklan tidak valid.'))->format('Y-m-d'),
                (\DateTimeImmutable::createFromFormat('!d/m/Y', $m[2]) ?: throw new \RuntimeException('Tanggal akhir iklan tidak valid.'))->format('Y-m-d'),
            ];
        } catch (\Throwable) {
            return [null, null];
        }
    }
}
