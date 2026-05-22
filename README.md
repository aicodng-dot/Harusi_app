# Wedding QR Admission System

A Laravel, MySQL, Blade, Tailwind CSS, and vanilla JavaScript web app for managing wedding invitations with secure QR admission passes.

## Features

- Desktop admin panel for adding guests, generating QR codes, downloading QR SVG files, and tracking pass usage.
- Mobile-first scanner app for gate personnel.
- Secure QR payloads: QR codes contain only a random token or verification URL, never guest name or phone number.
- Backend token validation using a stored SHA-256 token hash.
- Partial pass usage for double and family passes until the allowed count is exhausted.
- Hard maximum of 10 admissions per QR code.
- Scan logs and admission records for auditing.
- Pass states: unused, partially used, fully used, cancelled, and revoked.

## Privacy Model

Guest records store only:

- Guest name
- Phone number
- Pass type
- Number of guests allowed

The QR token is encrypted for QR regeneration and stored as a hash for lookup. Admission validation always happens on the backend.

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

Run migrations and optional sample data:

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

## Notes

The mobile camera scanner uses the browser `BarcodeDetector` API when available. If a browser does not support it, gate personnel can paste or type the QR URL/token into the scanner form.
