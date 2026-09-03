# MVP Release Notes

## Modal Sekolahnya Kalysa — source snapshot 2026-09-03

Fondasi MVP yang sudah tersedia di source ini:

- multi-store;
- login session Mia / Nandi dengan password awal dari environment dan hash database;
- master produk + variasi;
- HPP total dan admin historis;
- fixed fee sekali per No. Pesanan;
- import Order overlap-safe;
- import Penghasilan + Adjustment;
- pending order / pending settlement / missing HPP dipisahkan dari profit confirmed;
- expected vs actual settlement;
- biaya iklan manual per periode asli atau CSV Shopee Ads;
- tidak ada pembagian rata biaya iklan ke tanggal yang tidak diketahui;
- dashboard semua toko dan pending lintas toko;
- laporan profit per toko;
- Arus Settlement terpisah dari profit dan bukan rekonsiliasi bank/wallet;
- import preview, history, safe rollback, dan backup snapshot terbatas;
- closing bulanan dengan validasi data completeness dan stale marker.

## Baseline file contoh

Lihat `SAMPLE_ACCEPTANCE.md` untuk angka pembanding import file contoh user.

## Sebelum production

Source sudah lolos lint syntax, tetapi workspace pembuatan tidak dapat melakukan Composer install dan Vite build end-to-end. Ikuti `DEPLOYMENT.md`, jalankan health check, lalu uji import file contoh sebelum memasukkan data toko production.
