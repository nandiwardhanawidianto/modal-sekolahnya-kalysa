# First Run — urutan yang disarankan

Untuk setiap toko baru, urutan paling aman:

1. **Buat toko** di menu Kelola Toko.
2. **Upload Master Produk** Shopee.
3. **Upload Order** periode yang ingin dianalisis.
4. Kembali ke **Produk, HPP & Admin**.
   - Jika belum ada histori biaya sama sekali, aplikasi otomatis menyarankan `Berlaku mulai` dari tanggal order paling awal yang tersimpan.
   - Isi default admin toko dan fixed fee/order.
   - Isi HPP total per variasi. Bila satu produk punya HPP sama, cari produk lalu gunakan input massal.
5. **Upload Penghasilan**. Order selesai akan dicocokkan lewat No. Pesanan; Adjustment ikut dibaca.
6. **Biaya Iklan**:
   - input manual total periode, atau
   - upload CSV laporan iklan Shopee.
7. Buka **Laporan Toko**.
   - cek Pending,
   - cek missing HPP,
   - cek fee historis,
   - cek coverage iklan,
   - baru baca profit after ads.
8. Setelah bulan cukup lengkap, masuk **Closing Bulanan**.

## Upload berikutnya

Order dan Income boleh overlap.

Contoh:

- minggu pertama upload Order 1–7,
- tiga hari kemudian upload Order 1–10,
- sistem meng-update data 1–7 dan menambah 8–10, bukan menggandakan.

Untuk iklan, range tidak dibagi rata. Input 1–7 dan 8–10 dapat dijumlahkan presisi untuk laporan 1–10. Bila hanya punya total 1–31, laporan 1–7 akan menahan profit after ads sebagai belum presisi.

## Arti angka utama

- **Profit confirmed sebelum iklan** = settlement aktual + adjustment − HPP.
- **Potensi pending** = estimasi order belum settled menggunakan admin/fixed fee + HPP.
- **Proyeksi setelah iklan** = confirmed + potensi pending − iklan, hanya jika coverage iklan presisi.
- **Arus Settlement** bukan penarikan bank dan bukan saldo wallet aktual.
