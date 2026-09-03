KALYSA v1.2.0 - Cache navigasi + Kesehatan Data

Perubahan:
1. GET API disimpan sementara di cache memori browser sehingga kembali ke halaman yang sama tidak fetch ulang terus.
2. Cache otomatis dibuang setelah import, ubah HPP/fee, arsip produk, input iklan, tambah toko, rollback, dan perubahan data lain.
3. Request GET yang sama secara bersamaan dideduplikasi.
4. Tambah menu Kesehatan Data.
5. Kesehatan Data menampilkan HPP, settlement, iklan, fee, coverage order, status final, masalah order, dan sumber data terakhir.
6. Tombol "Periksa Ulang dari Database" melewati cache bila ingin memastikan data paling baru.

Tidak ada migration.
Tidak menambah dependency npm baru.
Tidak mengubah CostController baseline HPP/admin.
Tidak mengubah SimpleDashboardService v1.1.1.

Setelah overwrite:
cd backend
php artisan optimize:clear
cd ..\frontend
npm run build
Lalu Ctrl+Shift+R.
