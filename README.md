# Wedding QR Admission System

Harusi app: a wedding invite manager built with Laravel, MySQL, Blade, Tailwind CSS, and vanilla JavaScript.

The system manages wedding guest invitations with secure QR admission passes.

## Features

- Desktop admin panel for guests, QR codes, check-ins, reports, and scanner users.
- Mobile-first scanner app for gate personnel.
- Secure QR payloads: QR codes contain only a random token or verification URL, never guest name or phone number.
- Backend token validation using the database.
- Partial pass usage for double and special/family passes until the allowed count is exhausted.
- Hard maximum of 10 admissions per QR code.
- Scan attempts and admission records for auditing.
- Pass states: unused, partially used, fully used, cancelled, inactive, and revoked.
- CSV exports for reports, check-ins, and QR lists.
- Batch QR generation and ZIP download of QR PNG images.

## Privacy Model

Guest records store only:

- Guest name
- Phone number
- Pass type
- Number of guests allowed

QR codes do not expose guest details. Admission validation always happens on the backend.

## Requirements

- PHP 8.2+
- Composer
- MySQL
- Node.js and npm

## Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Create a MySQL database:

```sql
CREATE DATABASE wedding_qr_admission CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Update `.env` if needed:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=wedding_qr_admission
DB_USERNAME=root
DB_PASSWORD=
```

Run migrations and seed the default users:

```bash
php artisan migrate
php artisan db:seed
```

Seeded users:

- Admin: `admin@example.com` / `password`
- Scanner: `scanner@example.com` / `password`

Build assets and run locally:

```bash
npm run build
php artisan serve
```

Open:

- Login entry: `http://127.0.0.1:8000/login`
- Admin panel: `http://127.0.0.1:8000/admin/dashboard`
- Scanner app: `http://127.0.0.1:8000/scanner/dashboard`

## Testing

```bash
php artisan test
```

## Scanner Notes

The mobile scanner uses a browser QR scanner library with camera access. If camera scanning is unavailable, gate personnel can paste or type the QR URL/token into the manual scanner form.
