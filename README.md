# Wedding QR Admission System

A Laravel web app for creating wedding guest QR passes and scanning them at the venue entrance.

The system gives wedding administrators a desktop dashboard for guest invitations, secure QR code generation, check-in history, and reports. Gate personnel use a mobile-first scanner web app to validate QR passes and admit guests without exposing private guest details inside the QR code.

## Features

- Admin dashboard
- Guest management
- Single, double, and special/family passes
- Secure QR code generation
- QR code download
- Mobile scanner web app
- Confirm admission flow
- Prevent duplicate use
- Check-in history
- Reports
- CSV import/export
- Scanner user roles

## Tech Stack

- Laravel
- MySQL
- Blade
- Tailwind CSS
- Vanilla JavaScript

## Requirements

- PHP 8.2+
- Composer
- MySQL 8+ or MariaDB
- Node.js and npm
- A web browser with camera access for QR scanning

## Installation

1. Clone the repository:

```bash
git clone git@github.com:aicodng-dot/Harusi_app.git
cd Harusi_app
```

2. Install Composer dependencies:

```bash
composer install
```

3. Install npm dependencies:

```bash
npm install
```

4. Create `.env` from the example file:

```bash
cp .env.example .env
```

On Windows PowerShell:

```powershell
Copy-Item .env.example .env
```

5. Generate the Laravel app key:

```bash
php artisan key:generate
```

6. Configure the database in `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=wedding_qr_admission
DB_USERNAME=root
DB_PASSWORD=
```

7. Create the MySQL database:

```sql
CREATE DATABASE wedding_qr_admission CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

8. Run migrations:

```bash
php artisan migrate
```

9. Run seeders:

```bash
php artisan db:seed
```

The seeder creates default users and sample guest data. Do not run the sample seeder on a production database that already contains real event data.

10. Create the public storage link:

```bash
php artisan storage:link
```

11. Build frontend assets:

```bash
npm run build
```

12. Start the development server:

```bash
php artisan serve
```

Open the app at:

- Login: `http://127.0.0.1:8000/login`
- Admin panel: `http://127.0.0.1:8000/admin/dashboard`
- Scanner app: `http://127.0.0.1:8000/scanner/dashboard`

### Local HTTPS scanner testing

Phone camera access requires HTTPS when the app is opened from a LAN IP such as `192.168.100.114`. For local testing on Windows, generate a local certificate and run the included HTTPS proxy in front of Laravel.

1. Generate a local certificate for your computer's LAN IP:

```powershell
npm run https:cert -- -IpAddress 192.168.100.114
```

This creates:

- `storage/certs/harusi-local.pfx` for the local HTTPS proxy
- `storage/certs/harusi-local-ca.cer` to install on the phone as a trusted CA certificate

2. Install `storage/certs/harusi-local-ca.cer` on the phone as a trusted CA certificate, then restart Chrome if it was already open.

3. Build the frontend assets so the phone does not load insecure Vite dev assets:

```bash
npm run build
```

4. Start Laravel on localhost:

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

If PHP is not in your PATH, use the full PHP path, for example:

```powershell
C:\xampp\php\php.exe artisan serve --host=127.0.0.1 --port=8000
```

5. Start the HTTPS proxy in a second terminal:

```bash
npm run https:proxy
```

6. Open the scanner on the phone:

```text
https://192.168.100.114:8443/scanner/scan
```

For generated QR verification links, set `APP_URL=https://192.168.100.114:8443` in `.env` and clear Laravel config cache.

## Default Users

Admin:

- Email: `admin@example.com`
- Password: `password`

Scanner:

- Email: `scanner@example.com`
- Password: `password`

Change these passwords before using the system for a real event.

## Folder Structure

```text
app/
  Http/Controllers/       Admin, authentication, and scanner controllers
  Http/Middleware/        Role-based access middleware
  Models/                 User, Guest, QrCode, Checkin, Admission, ScanLog
  Services/               QR code generation service
database/
  migrations/             MySQL table definitions and constraints
  seeders/                Default admin/scanner users and sample data
public/
  storage/                Public symlink for generated QR images
resources/
  css/                    Tailwind CSS entry and component classes
  js/                     Vanilla JavaScript scanner/admin interactions
  views/                  Blade templates for auth, admin, and scanner UI
routes/
  web.php                 Public, admin, and scanner routes
tests/
  Feature/                Authentication, QR, admission, reports, import tests
docs/
  README.md               Extra project notes
screenshots/
  README.md               Placeholder for app screenshots
sample_guests.csv         CSV template for guest imports
```

## QR Code Security

QR codes never contain the guest name or phone number. Each QR code contains only a secure random token or a verification URL such as:

```text
https://your-domain.com/scanner/verify/random-secure-token
```

The scanner sends the token to the backend. The backend validates it against the `qr_codes` database table, checks whether the QR code is active, confirms that the related guest pass is valid, and recalculates remaining admissions before any admission is recorded.

Security behavior:

- Tokens are generated using secure random strings.
- Guest personal details are not embedded in the QR image.
- Revoked or deactivated QR codes do not validate.
- Regenerating a QR code replaces the old usable token.
- Each guest has only one current QR record.
- Admission actions run in database transactions.
- Backend validation prevents over-admission even if multiple gates scan the same pass.

## Pass Rules

- Single pass = admits 1 person
- Double pass = admits 2 people
- Special / Family pass = admits 3 to 10 people
- Maximum admissions per QR code = 10
- `used_entries` can never exceed `allowed_entries`
- Cancelled passes cannot be admitted
- Fully used passes cannot be admitted again

## Scanner Flow

1. Scanner user logs in.
2. Scanner user opens `/scanner/scan`.
3. Camera scans a QR code, or the token/URL is entered manually.
4. Backend validates the token and returns the pass status.
5. Scanner sees one of the clear status messages:
   - `VALID PASS`
   - `ADMITTED SUCCESSFULLY`
   - `ALREADY FULLY USED`
   - `INVALID QR CODE`
   - `CANCELLED PASS`
   - `REVOKED QR CODE`
   - `CONNECTION ERROR`
6. If valid, scanner chooses how many guests to admit.
7. Scanner taps Confirm Admission.
8. Backend recalculates remaining entries and records the admission.
9. Scanner can tap Scan Next for the next guest.

If camera scanning fails, scanner users can use Manual Search to find a guest by name or phone number and admit remaining entries without editing guest data.

## Admin Flow

1. Admin logs in and opens `/admin/dashboard`.
2. Admin creates guests manually or imports guests from CSV.
3. Admin assigns pass type and allowed entries.
4. Admin generates QR codes.
5. Admin downloads QR PNG files individually or as a ZIP.
6. Admin monitors check-ins, invalid attempts, cancelled/revoked scans, and reports.
7. Admin manages scanner users and gate names.
8. Admin can cancel passes, revoke QR codes, regenerate QR codes, and export CSV reports.

## CSV Import

Admins can import guests from CSV at:

```text
/admin/guests/import
```

Required columns:

```csv
name,phone_number,pass_type,allowed_entries
John Peter,0712345678,single,1
Mary Joseph,0755555555,double,2
Kimaro Family,0788888888,special,6
```

A sample file is included at [sample_guests.csv](sample_guests.csv).

## Deployment Notes

- Set `APP_ENV=production`.
- Set `APP_DEBUG=false`.
- Set `APP_URL` to the real domain.
- Use a strong database password.
- Run `php artisan key:generate` once per environment.
- Run `php artisan migrate --force` during deployment.
- Run `php artisan storage:link` so QR images can be served.
- Run `npm run build` and deploy the generated `public/build` assets.
- Configure the web server document root to the Laravel `public/` directory.
- Serve the scanner over HTTPS. Browser camera access usually requires HTTPS outside localhost.
- Change or remove seeded default users before real use.
- Back up the database before the event and before running migrations in production.
- Keep `.env` out of Git. The repository should only include `.env.example`.

## Testing

Run the automated test suite:

```bash
php artisan test
```

Build frontend assets:

```bash
npm run build
```

## License

This project is open-sourced under the MIT license.
