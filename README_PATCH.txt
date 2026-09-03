KALYSA v1.3.0 — DASHBOARD MIA MULTI-STORE

Pasang SETELAH v1.2.0.
Extract isi folder patch ke root project C:\laragon\www\modal-sekolahnya-kalysa lalu Replace/Overwrite.

Perubahan:
- Semua toko pada Dashboard Mia mendapat warna garis tetap berdasarkan store ID.
- Tidak ada batas jumlah garis toko.
- Hide/show toko satu per satu.
- Tampilkan Semua, Sembunyikan Semua, dan Solo toko.
- Filter grafik: Semua toko, Top 5 Profit, Yang Naik, Yang Turun.
- Toggle metrik: Profit, Omzet, Qty, Iklan, Margin.
- Profit total/ranking tetap menggunakan profit setelah iklan bila coverage iklan periode lengkap.
- Grafik profit TIDAK membagi iklan bulanan secara tebakan. Jika iklan per bucket tidak presisi, grafik memakai profit sebelum iklan dan memberi keterangan.
- Kontribusi profit per toko.
- Ranking toko + growth vs periode sebelumnya.
- Kartu Perlu Perhatian: HPP/fee/iklan incomplete, profit turun, margin turun, omzet turun, iklan naik terlalu cepat.
- Status Perlu Dikirim secara eksplisit tidak dihitung di Dashboard Mia; hanya Sedang Dikirim, Telah Dikirim, dan Selesai.
- Toko aktif tanpa transaksi/data pada periode tidak membuat profit Semua Toko menjadi 'iklan belum lengkap'.

Tidak ada migration.
Tidak ada npm dependency baru.
Tidak mengubah CostResolver / baseline HPP.

Sesudah overwrite:
1. cd C:\laragon\www\modal-sekolahnya-kalysa\backend
2. php artisan optimize:clear
3. cd ..\frontend
4. npm run build
5. Ctrl + Shift + R di browser

Sebelum tag v1.3.0, tes:
- Semua Toko dengan minimal 2 toko yang punya data.
- Hide/show dan Solo.
- Filter Yang Naik/Yang Turun.
- Toggle Profit/Omzet/Qty/Iklan/Margin.
- Pastikan total profit per toko dan gabungan sesuai laporan.
- Pastikan store yang tidak punya data pada periode tidak menghalangi profit gabungan.
