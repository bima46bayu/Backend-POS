<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

# README – Deploy Laravel ke Hostinger (Production)

Dokumen ini adalah panduan singkat & praktis untuk men‑deploy **Laravel** ke **Hostinger (shared hosting)** agar langsung jalan, termasuk kebutuhan PHP, ekstensi, *Composer*, *Sanctum*, cron, dan perizinan file. Sesuaikan nama domain, path, dan versi PHP dengan servermu.

---

## 0) Ringkasan Kebutuhan

* **PHP**: 8.2/8.3 (sesuaikan dengan proyek)
* **Web Server**: LiteSpeed/Apache (Hostinger)
* **Database**: MySQL/MariaDB
* **Composer**: wajib (server via SSH atau jalankan di lokal lalu upload **vendor/**)
* **Ekstensi PHP minimal (Laravel umum)**:

  * `bcmath`, `ctype`, `fileinfo`, `json`, `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`, `curl`, `zip`
  * Opsional: `gd` (gambar), `intl` (format lokal), `exif` (Exif image), `imagick`/`gmagick` (image processing)
* **Jika gunakan Excel (PhpSpreadsheet)**: `zip`, `mbstring`, `xml`, `xmlwriter`, `xmlreader`, `simplexml`, `dom`
* **Jika gunakan Autentikasi Laravel Sanctum**: butuh tabel `personal_access_tokens` & middleware `auth:sanctum`

---

## 1) Siapkan Domain & PHP

1. **Arahkan domain/subdomain** ke folder aplikasi.

   * hPanel → *Websites* → *Manage* → *Domains* → set **Document Root** ke folder Laravel **/public** (mis. `public_html/public`).
2. **Pilih versi PHP**:

   * hPanel → *Advanced* → *PHP Configuration* → *PHP Version* → pilih **sesuai proyek** (8.2/8.3). Simpan.
3. **Aktifkan ekstensi PHP**:

   * hPanel → *Advanced* → *PHP Configuration* → *Extensions* → aktifkan daftar pada bagian *Ringkasan Kebutuhan*.

> Catatan: Setelah mengubah konfigurasi PHP, gunakan tombol **Reset/Restart PHP** (jika ada) agar nilai baru terterapkan.

---

## 2) Upload Kode Aplikasi

Struktur direktori yang disarankan (contoh):

```
public_html/            # root hosting
├─ public/              # dokumen publik Laravel (Document Root domain mengarah ke sini)
├─ app/
├─ bootstrap/
├─ config/
├─ database/
├─ resources/
├─ routes/
├─ storage/
├─ vendor/              # hasil Composer (jika Composer dijalankan di lokal)
├─ artisan
├─ composer.json
└─ composer.lock
```

**Opsi A – Via SSH (disarankan):**

```bash
cd ~/domains/namadomainmu.com/public_html   # sesuaikan path
composer install --no-dev --optimize-autoloader
php artisan key:generate --force
```

**Opsi B – Tanpa SSH (jalankan di lokal):**

1. Di lokal: `composer install --no-dev --optimize-autoloader`
2. Upload seluruh project **termasuk folder vendor/** ke server
3. Di server, lanjut ke bagian konfigurasi `.env` dan perintah artisan (bisa via SSH/Terminal hPanel jika tersedia)

---

## 3) Konfigurasi `.env`

Duplikasi `.env.example` menjadi `.env`, lalu set nilai berikut:

```dotenv
APP_NAME="NamaAplikasi"
APP_ENV=production
APP_KEY=base64:xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
APP_DEBUG=false
APP_URL=https://domainmu.com

LOG_CHANNEL=stack
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=nama_db
DB_USERNAME=user_db
DB_PASSWORD=pass_db

CACHE_DRIVER=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync
```

> Setelah `.env` siap, jalankan (via SSH):

```bash
php artisan key:generate --force
php artisan storage:link
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

---

## 4) Instalasi Laravel Sanctum (Autentikasi API / SPA)

Jika proyek menggunakan autentikasi dengan **Laravel Sanctum**, lakukan langkah berikut di server:

```bash
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan migrate --force
```

### Konfigurasi `.env` untuk Sanctum

* **Mode Token (Bearer)**:

  * Gunakan header `Authorization: Bearer <token>`.
  * Tidak perlu `SANCTUM_STATEFUL_DOMAINS`.

* **Mode SPA (Cookie/Stateful)**:

  ```dotenv
  SESSION_DRIVER=file
  SESSION_DOMAIN=.domainmu.com
  SANCTUM_STATEFUL_DOMAINS=app.domainmu.com,domainmu.com,localhost:3000
  SESSION_SECURE_COOKIE=true
  ```

  Tambahkan middleware berikut di `app/Http/Kernel.php`:

  ```php
  \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
  ```

Pastikan tabel `personal_access_tokens` sudah termigrasi dan route auth dilindungi dengan `auth:sanctum`.

---

## 5) Perizinan File & Folder

Pastikan webserver bisa menulis di direktori berikut:

```bash
chmod -R 775 storage bootstrap/cache
```

---

## 6) Tuning PHP via `.user.ini`

Buat file **.user.ini** di **document root** domain (mis. `public_html/.user.ini`):

```ini
memory_limit = 512M
max_execution_time = 120
max_input_time = 120
post_max_size = 64M
upload_max_filesize = 64M
max_input_vars = 5000
date.timezone = Asia/Jakarta

opcache.enable = 1
opcache.memory_consumption = 128
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 1
opcache.revalidate_freq = 2

realpath_cache_size = 4096K
realpath_cache_ttl  = 600

display_errors = Off
log_errors = On
```

> **Reset/Restart PHP** di hPanel setelah mengubah `.user.ini`.

---

## 7) Cron & Queue (opsional)

### Scheduler Laravel

Jalankan scheduler tiap 1 menit:

```
*/1 * * * * /usr/bin/php82 /home/username/domains/domainmu.com/public_html/artisan schedule:run >> /dev/null 2>&1
```

### Queue Worker (jika pakai queue)

```
* * * * * /usr/bin/php82 /home/username/domains/domainmu.com/public_html/artisan queue:work --stop-when-empty >> /dev/null 2>&1
```

---

## 8) Fitur Import/Export Excel (PhpSpreadsheet)

Jika controller menggunakan **PhpSpreadsheet**:

```bash
composer require phpoffice/phpspreadsheet:^1.29 --no-dev
```

Aktifkan ekstensi: `zip`, `mbstring`, `xml`, `xmlwriter`, `xmlreader`, `simplexml`, `dom`.

---

## 9) SSL/HTTPS & CORS (bila API terpisah)

* Aktifkan **SSL** (Let’s Encrypt) di hPanel → *Security → SSL*.
* Set `APP_URL=https://domainmu.com`.
* Untuk SPA atau front-end terpisah, set `SANCTUM_STATEFUL_DOMAINS` dan `SESSION_DOMAIN` dengan benar.

---

## 10) Optimasi Produksi

```bash
php artisan optimize
composer install --no-dev --optimize-autoloader
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

---

## 11) Troubleshooting Cepat

* **500 Error**: cek `storage/logs/laravel.log`
* **Class not found (PhpSpreadsheet/Sanctum)**: belum composer require / vendor tidak terupload
* **ZipArchive not found**: aktifkan ekstensi `zip`
* **File terlalu besar**: naikkan `upload_max_filesize` & `post_max_size`
* **403/404 route**: domain harus diarahkan ke `/public`

---

## 12) Keamanan Dasar

* `APP_DEBUG=false` saat production
* Hapus file `phpinfo.php` setelah pengecekan
* Batasi akses ke folder selain `/public`
* Perbarui dependensi secara berkala

---

### Selesai

Jika mengikuti langkah di atas, Laravel (termasuk Sanctum & PhpSpreadsheet) akan langsung jalan di Hostinger. Jika error, buka `storage/logs/laravel.log` untuk detailnya.
