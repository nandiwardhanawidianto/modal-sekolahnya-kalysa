# Formula Profit

## 1. Snapshot penjualan

Nilai penjualan item selalu berasal dari file Order:

`Harga Setelah Diskon × Jumlah = Subtotal Pesanan`

Harga master produk bukan sumber nilai transaksi historis.

## 2. Expected settlement

Untuk satu No. Pesanan:

`Expected Settlement = Σ Subtotal Item - Σ(Admin% Item × Subtotal Item) - Fixed Fee`

Admin per variasi dipakai bila tersedia. Jika kosong, gunakan default admin toko pada tanggal order.

Fixed fee dipilih dari histori fee toko sesuai tanggal order dan hanya sekali per No. Pesanan.

## 3. Expected profit sebelum iklan

`Expected Profit = Expected Settlement - Σ(HPP per variasi × Qty)`

Jika satu item belum memiliki HPP yang dapat dipastikan, seluruh profit order diberi status belum lengkap. HPP tidak pernah diasumsikan nol.

## 4. Confirmed profit sebelum iklan

Jika laporan Penghasilan sudah memiliki No. Pesanan:

`Confirmed Profit = Total Penghasilan + Σ Adjustment Order - HPP Order`

`Total Penghasilan` adalah sumber kebenaran aktual. Admin tidak dikurangi dua kali.

## 5. Profit periode setelah iklan

Jika coverage iklan presisi:

`Confirmed After Ads = Σ Confirmed Profit - Ad Spend`

`Projected After Ads = Σ Confirmed Profit + Σ Potential Pending Profit - Ad Spend`

Projected hanya estimasi.

Jika coverage iklan tidak presisi, kedua angka after-ads ditahan (`null`) dan UI menampilkan alasan. Sistem **tidak membagi total iklan secara rata ke tanggal**.

## 6. Pending

- **Pending Order**: status belum selesai / masih pengiriman.
- **Pending Settlement**: status selesai tetapi No. Pesanan belum ada pada Penghasilan yang diimport.
- **Pending Data**: HPP/mapping belum cukup untuk menghitung profit.

Pending tidak dihitung sebagai rugi. Omzet, qty, nomor pesanan, status, umur pending, dan estimasi profitnya tetap ditampilkan terpisah.

## 7. Profit vs Settlement vs Penarikan Bank

**Profit** dilaporkan berdasarkan tanggal order.

**Arus Settlement** dilaporkan berdasarkan Tanggal Dana Dilepaskan dan tanggal adjustment.

**Penarikan Bank** belum dianggap sama dengan file Penghasilan. Dana yang dilepas dapat masuk Saldo Penjual terlebih dahulu, dipakai top-up iklan, tersisa di saldo, lalu ditarik pada waktu yang berbeda.

Order 31 Agustus yang cair 5 September tetap menjadi profit penjualan Agustus, tetapi settlement-nya terjadi September.
