# Acceptance Check dari file contoh yang diberikan

Angka ini dipakai sebagai pembanding saat deployment pertama.

## Master produk

File `mass_update_sales_info_10663057_20260903222422.xlsx`:

- 24 produk/listing.
- 367 variasi.

## Order Agustus 2026

File `Order.all.20260801_20260831.xlsx`:

- 17.163 baris item.
- 2.757 No. Pesanan unik.
- Selesai: 2.247.
- Batal: 298.
- Sedang Dikirim: 113.
- Telah Dikirim: 98.
- Perlu Dikirim: 1.

Semua 17.163 baris memiliki nama produk yang dapat dihubungkan ke salah satu listing master contoh. Nama variasi historis tidak selalu sama dengan nama variasi master saat ini, sehingga aplikasi tidak bergantung pada exact variation name saja.

## Penghasilan 1 Agustus – 3 September 2026

File `Income.sudah dilepas.id.20260801_20260903.xlsx`:

- 2.909 settlement level Order pada keseluruhan file (termasuk order sebelum Agustus).
- 2.247 order Selesai dari file Order Agustus ditemukan di Penghasilan contoh.
- 22 adjustment pada sheet Adjustment.
- Jeda order → dana dilepas pada data contoh: median 5 hari, rata-rata sekitar 5,81 hari, maksimum 28 hari.

Karena itu laporan profit tidak boleh menganggap order akhir bulan yang belum cair sebagai kerugian.

## Iklan Shopee

File `Data+Keseluruhan+Iklan+Shopee-03_08_2026-03_09_2026.csv`:

- Username: `nazorafashion`.
- Nama toko: `Nazora Fashion`.
- ID toko: `10663057`.
- Periode: 3 Agustus – 3 September 2026.
- 11 baris iklan.
- Total `Biaya`: **Rp7.403.571**.
- `Omzet Penjualan` atribusi iklan: **Rp46.957.085** (bukan omzet ledger utama).
- Biaya tanpa Kode Produk: **Rp69.151**.

Karena laporan iklan ini agregat 3 Agustus–3 September, filter 1–31 Agustus tidak boleh mengambil sebagian Rp7.403.571. Sistem harus menandainya sebagai biaya iklan belum presisi untuk filter tersebut.
