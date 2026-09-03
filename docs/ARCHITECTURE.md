# Arsitektur Data

```text
Store
├── Products
│   └── Product Variants
│       └── Variant Cost History (HPP + admin %)
├── Store Fee History (default admin + fixed fee/order)
├── Orders
│   └── Order Items
├── Settlements
├── Adjustments
├── Ad Cost Periods
├── Import Batches / Errors / Coverage
└── Monthly Closings
```

## Kunci penting

- `store_id + order_number` unik untuk order.
- `store_id + shopee_product_id` unik untuk listing.
- `store_id + shopee_variation_id` unik untuk variasi master.
- Order Shopee contoh tidak memuat variation ID, sehingga matching memakai:
  1. nama produk + nama variasi exact,
  2. canonical variation name jika unik,
  3. product + SKU jika unik,
  4. jika tetap ambigu, order masih terhubung ke produk induk dan HPP hanya diwarisi bila **semua kandidat** biaya sama.

Tidak ada fuzzy guessing yang memaksakan HPP.

## Upload overlap Order / Income

Laporan tidak disimpan sebagai agregat 1–7 / 1–10. Transaksi mentah disimpan. Karena itu:

- upload 1–7,
- lalu upload 1–10,
- lalu lihat 3–8,

semuanya tetap valid tanpa duplikasi.

## Biaya iklan

Biaya iklan sengaja berbeda dari Order/Income. Shopee dapat memberi laporan agregat per rentang tanpa breakdown harian. Karena itu `Ad Cost Periods` menyimpan:

- `start_date`
- `end_date`
- `amount`
- sumber `manual` atau `shopee_csv`
- metadata/breakdown produk dari CSV jika tersedia

Contoh:

```text
1–7 Agustus  Rp1.500.000
8–10 Agustus Rp  600.000
```

Laporan 1–10 dapat memakai Rp2.100.000 secara presisi.

Tetapi jika satu-satunya data adalah:

```text
1–31 Agustus Rp7.200.000
```

maka laporan 1–7 **tidak** membagi Rp7,2 juta / 31. Profit after ads 1–7 ditahan sebagai belum presisi.

Periode iklan yang overlap dicegah. Replace hanya aman bila range baru mencakup penuh periode lama yang akan diganti.
