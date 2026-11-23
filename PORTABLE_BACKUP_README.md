# Harusi App Portable Backup

This package is a private, runnable backup of the Harusi app. It includes the application source at backup time, Git history, installed PHP dependencies, compiled frontend assets, uploaded/generated app files, the active `.env`, and a MySQL/MariaDB dump in `database-backup/wedding_qr_admission.sql`.

## Security warning

Keep this backup private. It contains application secrets such as `APP_KEY`, database settings, local certificates, uploaded files, and possibly guest or admission data.

## Requirements on the other PC

- PHP 8.2 or newer
- MySQL 8 or MariaDB
- Composer recommended, although `vendor/` is included in this backup
- Node.js/npm only if you need to rebuild frontend assets; `public/build/` is included

## Restore with XAMPP on Windows

1. Copy this backup folder, for example to `C:\xampp\htdocs\Harusi-app`.
2. Start MySQL from XAMPP.
3. Open PowerShell inside the extracted folder.
4. Import the database:

```powershell
Get-Content .\database-backup\wedding_qr_admission.sql -Raw | & C:\xampp\mysql\bin\mysql.exe -u root
```

If your MySQL user has a password, add `-p` and enter it when prompted.

5. Edit `.env` if needed:

- Set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` for the new PC.
- Set `APP_URL` to the URL you will use on the new PC.
- Keep the existing `APP_KEY` unless you intentionally want to invalidate encrypted data and sessions.

6. Clear machine-specific caches and finish setup:

```powershell
& C:\xampp\php\php.exe artisan optimize:clear
& C:\xampp\php\php.exe artisan migrate --force
& C:\xampp\php\php.exe artisan storage:link
```

7. Run the app:

```powershell
& C:\xampp\php\php.exe artisan serve --host=127.0.0.1 --port=8000
```

Then open `http://127.0.0.1:8000`.

Do not run database seeders after importing this dump unless you intentionally want to add or overwrite test data.

## Notes

Local HTTPS certificates in `storage/certs/` are machine/IP-specific. If HTTPS is needed on the other PC, regenerate the local certificate there.

If dependencies need to be refreshed, run:

```powershell
composer install
npm install
npm run build
```
