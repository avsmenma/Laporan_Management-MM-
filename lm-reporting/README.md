# Sistem Pelaporan LM PTPN IV Regional V

Aplikasi Laravel 12 untuk Report Viewer Laporan Manajemen biaya produksi kebun dan pabrik PTPN IV Regional V.

## Stack

- Laravel 12, PHP 8.3+, MySQL 8.
- Auth session + Sanctum untuk fondasi API.
- Export: Maatwebsite Excel dan DomPDF.
- Frontend: Vite, Tailwind CSS, Alpine.js, Tabulator.js.

## Struktur Awal

- `app/Domain/Report/Services`: service materialisasi LM14, LM13, dan LM16.
- `app/Domain/Import`: import SAP, form pabrik, dan master pembanding.
- `app/Http/Controllers/Report`: read layer laporan.
- `app/Http/Controllers/Import`: endpoint import.
- `app/Http/Controllers/Master`: master data dan user.

## Akun Seed

Semua akun memakai password `password`.

- Viewer: `viewer@lm.test`
- Operator: `operator@lm.test`
- Admin: `admin@lm.test`

## Setup Lokal

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run dev
php artisan serve
```

Konfigurasi default database mengarah ke MySQL database `lm_reporting`.
