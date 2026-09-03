# Build / Validation Status

## Sudah diuji di workspace

- Seluruh file PHP lolos `php -l` pada PHP 8.4.
- Seluruh JSX/JS frontend lolos parsing menggunakan TypeScript parser dengan JSX mode.
- CSV iklan contoh dibaca langsung oleh `ShopeeAdsCsvReader`:
  - seller `nazorafashion`,
  - periode 2026-08-03 s.d. 2026-09-03,
  - 11 baris,
  - biaya Rp7.403.571,
  - GMV atribusi Rp46.957.085,
  - biaya tanpa Kode Produk Rp69.151.
- Struktur raw XLSX contoh diaudit dan header yang dibutuhkan importer tersedia.
- Acceptance data contoh:
  - Master Produk: 24 listing / 367 variasi.
  - Order Agustus: 17.163 baris / 2.757 order unik.
  - Status: 2.247 Selesai, 298 Batal, 113 Sedang Dikirim, 98 Telah Dikirim, 1 Perlu Dikirim.
  - Income: 2.909 settlement level Order dan 22 Adjustment pada file contoh.
  - Kolom auto top-up iklan pada file Income contoh tidak memiliki nilai non-zero.

## Belum dapat dijalankan end-to-end di workspace ini

Environment kerja saat ini tidak menyediakan Composer/vendor Laravel dan ekstensi PHP `zip/xmlreader/simplexml/dom` yang diperlukan untuk menjalankan importer XLSX PHP secara langsung.

`npm install` juga timeout pada akses registry, sehingga bundle Vite belum dihasilkan di workspace ini.

Karena itu sebelum production perlu:

1. `composer install`
2. `php artisan migrate`
3. `npm install && npm run build`
4. import tiga file contoh dan cocokkan `SAMPLE_ACCEPTANCE.md`
5. cek `/api/system/health`

Source dirancang agar instalasi gagal jelas jika ekstensi PHP penting tidak tersedia (`ext-zip`, `ext-xmlreader`, `ext-pdo_mysql`, dll.), daripada baru gagal diam-diam saat import.
