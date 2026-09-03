# Modal Sekolahnya Kalysa

Aplikasi multi-toko untuk menghitung profit Shopee dari **Order + Penghasilan + HPP + admin + iklan**, dengan pending, refund/adjustment, arus settlement, data coverage, dan closing bulanan dipisahkan dengan jelas.

## Prinsip hitung

### Estimasi per pesanan (sebelum Penghasilan tersedia)

`Omzet setelah diskon - admin % - biaya tetap per No. Pesanan - HPP total`

Biaya tetap hanya dikenakan **1 kali per No. Pesanan**, bukan per item/baris.

### Profit confirmed (setelah Penghasilan tersedia)

`Total Penghasilan Shopee + Adjustment - HPP total`

Admin tidak dikurangi lagi karena sudah tercermin di Total Penghasilan. Admin yang diinput dipakai untuk estimasi dan cross-check expected vs actual.

### Profit periode setelah iklan

`Σ profit confirmed order - biaya iklan periode`

Iklan disimpan sebagai **periode asli**, tidak dibagi rata ke hari. Profit after ads hanya dihitung bila periode iklan mencakup filter laporan secara presisi. Jika tidak, aplikasi menampilkan profit sebelum iklan + status iklan belum presisi.

Aplikasi juga menampilkan **Projected** = confirmed + estimasi pending - iklan, hanya bila data iklan presisi. Projected tidak pernah diberi label final.

## Fitur MVP

- Multi-store dari awal.
- Login session Mia/Nandi (seed via environment, password tidak disimpan plaintext di source).
- Master produk/variasi Shopee.
- HPP total + admin % per variasi dengan histori tanggal berlaku.
- Default admin toko + biaya tetap per pesanan dengan histori tanggal berlaku.
- Upload Order overlap-safe (`upsert`).
- Upload Penghasilan dan Adjustment.
- Upload CSV laporan iklan Shopee **atau** input manual total iklan per periode.
- Iklan tidak pernah dialokasikan harian secara tebakan.
- Preview sebelum import.
- Riwayat import dan safe rollback dengan snapshot.
- Remap otomatis order lama setelah master produk dilengkapi.
- Pending order, pending settlement, missing HPP.
- Actual vs expected settlement / anomaly.
- Dashboard semua toko.
- Laporan per toko.
- Arus settlement berdasarkan tanggal dana dilepas, dipisahkan dari profit dan tidak disamakan dengan penarikan bank.
- Data coverage.
- Closing bulanan + penanda stale jika data lama berubah.
- Audit/activity log di database.
- Responsive/mobile-friendly.

## Struktur

- `backend/` — Laravel 12 API + session auth + importer XLSX/CSV ringan.
- `frontend/` — React + Vite.
- `docs/` — rumus, arsitektur, acceptance sample, deployment.

## Mulai lokal / deployment

Lihat `docs/DEPLOYMENT.md`.
