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
- Excel guest import and QR-list export
- CSV report export
- Scanner user roles

## Tech Stack

- Laravel
- MySQL
- Blade
- Tailwind CSS
- Vanilla JavaScript
- PhpSpreadsheet (`phpoffice/phpspreadsheet`) for XLSX import and export

## Requirements

- PHP 8.2+
- PHP extensions required by PhpSpreadsheet: `gd` and `zip`
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

### WhatsApp Business API Credentials

The app is prepared for Meta WhatsApp Cloud API only. Add real WhatsApp Business credentials to your local or production `.env` file, not to `.env.example`, README files, source code, or the admin UI.

```env
WHATSAPP_PROVIDER=meta
WHATSAPP_API_BASE_URL=https://graph.facebook.com
WHATSAPP_API_VERSION=v21.0
WHATSAPP_ACCESS_TOKEN=your_meta_access_token
WHATSAPP_PHONE_NUMBER_ID=your_meta_phone_number_id
WHATSAPP_BUSINESS_ACCOUNT_ID=your_meta_business_account_id
WHATSAPP_WEBHOOK_VERIFY_TOKEN=your_private_webhook_verify_token
WHATSAPP_APP_SECRET=your_meta_app_secret
```

These values come from your Meta developer app and WhatsApp Business account setup. Leave them blank while testing if you do not want real WhatsApp messages to be sent. When credentials are missing, the system uses the null WhatsApp provider and records skipped notification logs instead of sending messages.

After changing WhatsApp values in production, clear cached config:

```bash
php artisan config:clear
```

7. Create the MySQL database:

```sql
CREATE DATABASE wedding_qr_admission CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

8. Run migrations:

```bash
php artisan migrate
```

9. Optional: create local/testing demo data on six empty target tables:

```bash
php artisan db:seed
```

The default seeder runs only when the configured environment is exactly `local` or `testing`. It refuses staging/production and other labels even with `--force`. The six sample-data target tables (users, guests, QR codes, admissions, checkins and scan logs) must all be empty. Existing rows are never reset or deleted; rerunning the seeder refuses instead. Sample inserts share one transaction. This environment/target check does not verify database identity or whole-database emptiness. See [demo seeding scope](docs/DEMO_SEEDING.md).

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

If port `8000` is already occupied or the app does not respond, start Laravel on another port and point the proxy to it:

```powershell
C:\xampp\php\php.exe artisan serve --host=127.0.0.1 --port=8001
$env:HARUSI_HTTPS_TARGET = 'http://127.0.0.1:8001'
npm run https:proxy
```

6. Open the scanner on the phone:

```text
https://192.168.100.114:8443/scanner/scan
```

For generated QR verification links, set `APP_URL=https://192.168.100.114:8443` in `.env` and clear Laravel config cache.

## Local/testing demo users

Admin:

- Email: `admin@example.com`
- Password: `password`

Scanner:

- Email: `scanner@example.com`
- Password: `password`

These are disposable local/testing fixtures, not production account setup. The default-seeder guard does not revoke demo credentials already present in an existing installation. The separate recovery --apply writer also has its own exact local/testing environment guard. It remains legacy recovery tooling; real administrator setup/revocation requires a separate reviewed workflow. See [recovery script scope](docs/RECOVERY_SCRIPT_SAFETY.md).

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
- Special / Family pass = admits 3 to 100 people
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
2. Admin creates guests manually or imports guests from an Excel `.xlsx` workbook.
3. Admin assigns pass type and allowed entries.
4. Admin generates QR codes.
5. Admin downloads QR PNG files individually or as a ZIP, or exports the QR list as an Excel workbook.
6. Admin monitors check-ins, invalid attempts, cancelled/revoked scans, and reports.
7. Admin manages scanner users and gate names.
8. Admin can cancel passes, revoke QR codes, regenerate QR codes, and export CSV reports.

## Excel Guest Import (XLSX)

Admins can import guests from a Microsoft Excel `.xlsx` workbook at:

```text
/admin/guests/import
```

Use **Download Excel Template** on the import page to get a correctly formatted workbook. The first worksheet must contain these required logical columns:

| name | phone_number | pass_type | allowed_entries |
| --- | --- | --- | --- |
| John Peter | 0712345678 | single | 1 |
| Mary Joseph | 0755555555 | double | 2 |
| Kimaro Family | 0788888888 | special | 6 |

Header matching is case-insensitive and normalizes spaces to underscores, so headers such as `Phone Number`, `Pass Type`, and `Allowed Entries` are accepted. Phone numbers are read as text before the existing phone normalization and validation run, which protects leading zeroes. `allowed_entries` is validated as an integer under the existing pass rules.

PhpSpreadsheet reads the workbook, converts worksheet rows into the existing guest-import pipeline, and reports the Excel row number for invalid entries.

## QR List Excel Export

On `/admin/qr-codes`, use **Export QR list Excel** to download every guest in the currently selected event as an `.xlsx` workbook. Page search and QR-status filter parameters are intentionally not applied. The export preserves these 11 columns: `Guest ID`, `Guest name`, `Phone number`, `Pass type`, `Allowed entries`, `Used entries`, `Guest status`, `QR status`, `Generated at`, `Revoked at`, and `Download file name`.

Phone numbers and every other text cell are written explicitly as text, including `Download file name`, whose QR PNG filename contains the four-digit admission code. This preserves leading zeroes and makes formula-like text inert. Full QR tokens and standalone manual admission codes remain undisclosed.

PhpSpreadsheet generates both the downloadable guest template and QR-list workbook. QR image downloads, **Download all ZIP**, **Export selected ZIP**, and the Kadify offline-scanner ZIP format remain unchanged.

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
