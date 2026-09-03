# Deployment — Laravel + React di shared hosting/cPanel

## Requirement

- PHP 8.2+ (disarankan PHP 8.4 bila tersedia).
- MySQL/MariaDB.
- Composer 2.
- Ekstensi PHP: `ctype`, `dom`, `fileinfo`, `json`, `mbstring`, `openssl`, `pdo_mysql`, `simplexml`, `tokenizer`, `xml`, `xmlreader`, `zip`.
- Node.js hanya dibutuhkan untuk build frontend; build bisa dilakukan di PC lokal. Untuk Vite 8 gunakan Node.js 20.19+ atau 22.12+.

## 1. Backend

```bash
cd backend
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Buat database MySQL dan isi `.env`.

Set password awal dua akun hanya di `.env`:

```env
INITIAL_LOGIN_PASSWORD=GANTI_DENGAN_PASSWORD_PRODUKSI
```

Jangan commit `.env`.

Kemudian:

```bash
php artisan migrate --force
php artisan db:seed --class=InitialUsersSeeder --force
php artisan optimize
```

Seeder membuat username `mia` dan `nandi`. Password disimpan dalam hash.

## 2. Frontend

Di PC/local:

```bash
cd frontend
npm install
npm run build
```

Output otomatis masuk ke:

`backend/public/app/`

## 3. cPanel

Direkomendasikan aplikasi berada di luar `public_html`, misalnya:

```text
/home/USERNAME/apps/modal-sekolahnya-kalysa/backend
```

Document Root subdomain diarahkan ke:

```text
/home/USERNAME/apps/modal-sekolahnya-kalysa/backend/public
```

Jangan arahkan document root ke root Laravel.

Pastikan writable:

```text
backend/storage
backend/bootstrap/cache
```

Umumnya permission 775 sudah cukup, menyesuaikan hosting.

## 4. Tes setelah online

1. Login sebagai Mia/Nandi.
2. Buat toko `Nazora Fashion` dan isi username Shopee `nazorafashion`.
3. Upload master produk melalui Preview → Konfirmasi.
4. Isi default fee dan HPP/admin produk.
5. Preview + import Order contoh.
6. Preview + import Penghasilan contoh.
7. Cocokkan angka di `SAMPLE_ACCEPTANCE.md`.
8. Input iklan manual per periode atau upload CSV laporan iklan Shopee.
9. Pastikan laporan iklan contoh terbaca Rp7.403.571 untuk periode 3 Agu–3 Sep, lalu buka Laporan Toko dan periksa Pending + Data Coverage.
10. Buka `/api/system/health` setelah login untuk memastikan ekstensi server aktif.

## Jika cPanel tidak menyediakan Composer/SSH

Jalankan `composer install --no-dev --optimize-autoloader` di lokal menggunakan PHP yang kompatibel, lalu upload folder backend beserta `vendor`. Tetap buat `.env` di server dan jalankan migration melalui Terminal cPanel bila tersedia. Jika Terminal tidak tersedia sama sekali, migration dapat dijalankan sementara melalui SSH/support hosting; jangan membuat endpoint web publik untuk menjalankan migration.

## Alur pertama

Setelah aplikasi hidup, ikuti `FIRST_RUN.md`. Status validasi source ada di `BUILD_STATUS.md`.
