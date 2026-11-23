# Offline scanner package signing keys (Ed25519)

Status (2026-11-23): server signing, key tooling and verification are implemented and tested with synthetic keys. **No production key exists yet.** Production signing stays disabled until the owner generates the real key with the procedure below and installs it. The Android scanner source is not in this repository, so its trust-store change is specified here but not verified.

## What the server does

- `PackageSignatureService` signs every offline package (`.wqrpkg`) when `OFFLINE_SCANNER_PACKAGE_SIGNATURE_ALGORITHM=ed25519` (the default).
- **Private key setting:** `OFFLINE_SCANNER_PACKAGE_ED25519_PRIVATE_KEY_BASE64`. It must be strict base64 of either:
  - a **32-byte Ed25519 seed** (recommended; what the generator produces), or
  - a **64-byte libsodium secret key** (seed + public key).

  Anything else fails package generation with a clear error. No session or package is created.
- **Key id:** `OFFLINE_SCANNER_PACKAGE_SIGNING_KEY_ID` (default `server-key-1`). It is copied into each package as `signature_key_id` so the scanner can pick the matching public key during rotation. Use a dated id such as `harusi-2026-11`.
- **What is signed:** the canonical JSON of the package envelope without the three fields `signature_algorithm`, `signature_key_id` and `digital_signature`. Canonical JSON (`App\Services\CanonicalJson`) means:
  - object keys sorted with byte-wise string order, recursively
  - lists kept in order
  - `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION`
  - no whitespace
- **Package fields:** `signature_algorithm: "Ed25519"`, `signature_key_id`, and `digital_signature` (base64 of the 64-byte detached signature).
- **Requirement:** the PHP **Sodium** extension (`extension=sodium` in `php.ini`). Check with `php -m | grep sodium`.

## Generate the production key (once, by the owner, on a trusted machine)

Never generate a key from a deployment script, CI job or migration. A new key makes every installed scanner reject new packages until it trusts the new public key.

```bash
php artisan offline-scanner:signing-key-generate --output=/secure/location/harusi-offline-signing.key
```

- The command refuses paths inside the application directory, so the key can't be committed or served. It also refuses to overwrite an existing file.
- It writes only the base64 seed to that file, with mode 0600 where supported.
- It prints only the **public key** (base64) and its **fingerprint** (`SHA256:<hex>`). Record both.
- Keep the key file in a password manager or vault, then delete other copies. Never paste it into chat, tickets, logs or Git.

## Install it on the server

1. Put the file's single line into the server's `.env`. Never put it in `.env.example`.
   ```
   OFFLINE_SCANNER_PACKAGE_SIGNATURE_ALGORITHM=ed25519
   OFFLINE_SCANNER_PACKAGE_SIGNING_KEY_ID=harusi-2026-11
   OFFLINE_SCANNER_PACKAGE_ED25519_PRIVATE_KEY_BASE64=<contents of the key file>
   OFFLINE_SCANNER_PACKAGE_ALLOW_HMAC_FALLBACK=false
   ```
2. If configuration is cached: `php artisan config:clear` (and `php artisan config:cache` again in production).
3. Check without revealing the key:
   ```bash
   php artisan offline-scanner:signing-key-check --public-key=<public key from step "Generate">
   ```
   Expected output: the server public key, the fingerprint, `Self-test passed`, and `The given public key MATCHES the configured server key`. The private key is never printed.

## Configure the Android scanner (trust the public key)

The Android source is not in this repository (see [Android scanner](ANDROID_SCANNER.md)), so the exact file can't be named here. The app must:

1. Ship a trust store that maps `signature_key_id` to the 32-byte Ed25519 public key (base64), for example `{"harusi-2026-11": "<public key base64>"}`. It must be compiled into the app or provisioned at device enrollment, never downloaded inside the package.
2. Refuse any package whose `signature_algorithm` is not `Ed25519` (no HMAC or "none" downgrade), or whose `signature_key_id` is unknown.
3. Remove the three signature fields, re-encode the remaining JSON canonically as described above, and verify `digital_signature` with Ed25519 *before* decrypting or trusting any field.
4. Show the fingerprint in an about or diagnostics screen, so staff can compare it with the server's `signing-key-check` output.

Reference implementations to copy: `App\Services\PackageSignatureKeys::verifyPackage()`, and the independent re-implementation in `tests/Feature/OfflineSigningKeyTest.php`.

## Verify a real package end to end

After creating a session (Admin → Offline Scanner), verify the downloaded `.wqrpkg` exactly as the scanner will:

```bash
php artisan offline-scanner:signing-key-check --package=/path/to/package.wqrpkg --public-key=<public key the Android app trusts>
```

`Package signature VALID.` means the server key and the Android trust store match. `INVALID` means the app would reject the package.

## Rotation

1. Generate a new key with a new key id.
2. Release an Android build whose trust store has both the old and new ids.
3. Once devices are updated, switch the server `.env` to the new key and id.
4. Remove the old id in a later release.

## Tested (synthetic keys only)

`tests/Feature/OfflineSigningKeyTest.php`:
- An Ed25519 package generated through the real admin route verifies with the app helper and with an independent canonical re-implementation.
- Tampered ciphertext, a different key, and an algorithm downgrade are each rejected.
- The 64-byte secret-key encoding is accepted; wrong sizes are rejected.
- A missing key creates no session or package.
- The check command prints only public material and validates or rejects package files.
- The generator never prints the seed, refuses to overwrite, and refuses paths inside the application.

**Not verified:** real Android verification, the real production key, and hosting `php.ini`.
