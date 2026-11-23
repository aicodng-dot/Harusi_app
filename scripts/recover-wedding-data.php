<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Guest;
use App\Models\QrCode;
use App\Models\User;
use App\Services\PhoneNumberService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$options = getopt('', ['base-csv:', 'supplemental-csv:', 'qr-dir:', 'apply']);
$apply = array_key_exists('apply', $options);

if ($apply && ! $app->environment(['local', 'testing'])) {
    fwrite(STDERR, "Recovery apply is allowed only in local or testing environments.\n");
    exit(2);
}

$resolveInputPath = static fn (mixed $value): string|false => is_string($value) && $value !== '' ? realpath($value) : false;
$baseCsv = $resolveInputPath($options['base-csv'] ?? null);
$supplementalCsv = $resolveInputPath($options['supplemental-csv'] ?? null);
$qrDirectory = $resolveInputPath($options['qr-dir'] ?? null);

if (! $baseCsv || ! is_file($baseCsv) || ! $supplementalCsv || ! is_file($supplementalCsv) || ! $qrDirectory || ! is_dir($qrDirectory)) {
    fwrite(STDERR, "Usage: php scripts/recover-wedding-data.php --base-csv=<path> --supplemental-csv=<path> --qr-dir=<path> [--apply]\n");
    exit(1);
}

/**
 * @return list<array<string, string>>
 */
function readCsvRows(string $path): array
{
    $handle = fopen($path, 'rb');
    if (! $handle) {
        throw new RuntimeException('Unable to read '.$path);
    }

    $headings = fgetcsv($handle);
    if (! is_array($headings)) {
        throw new RuntimeException('CSV has no heading row: '.$path);
    }

    $headings = array_map(static fn ($heading): string => trim((string) $heading), $headings);
    $rows = [];

    while (($values = fgetcsv($handle)) !== false) {
        if (count(array_filter($values, static fn ($value): bool => trim((string) $value) !== '')) === 0) {
            continue;
        }

        $values = array_pad($values, count($headings), '');
        $rows[] = array_combine($headings, array_map(static fn ($value): string => trim((string) $value), $values));
    }

    fclose($handle);

    return $rows;
}

function normalizedName(string $name): string
{
    return mb_strtolower(preg_replace('/\s+/', ' ', trim($name)) ?? '');
}

function passTypeFromLabel(string $label): string
{
    return match (strtolower(trim($label))) {
        'single', 'single pass' => Guest::PASS_SINGLE,
        'double', 'double pass' => Guest::PASS_DOUBLE,
        'special', 'special pass' => Guest::PASS_SPECIAL,
        default => throw new RuntimeException('Unknown pass type: '.$label),
    };
}

/**
 * @return array{token: string, path: string, manual_code: string, modified_at: string}
 */
function qrAsset(string $path, string $qrDirectory): array
{
    $filename = basename($path);

    if (! preg_match('/^guest-(\d+)-([A-Za-z0-9]+)\.png$/', $filename, $matches)) {
        throw new RuntimeException('Unexpected QR filename: '.$filename);
    }

    $contents = file_get_contents($path);
    if ($contents === false || ! preg_match("/Manual admission code\x00(\d{4})/", $contents, $manualCode)) {
        throw new RuntimeException('Manual code metadata is missing from '.$filename);
    }

    return [
        'token' => $matches[2],
        'path' => 'qr-codes/on_wedding/'.$filename,
        'manual_code' => $manualCode[1],
        'modified_at' => Carbon::createFromTimestamp(filemtime($path))->toDateTimeString(),
    ];
}

$baseRows = readCsvRows($baseCsv);
$supplementalRows = readCsvRows($supplementalCsv);
$phoneNumbers = app(PhoneNumberService::class);
$guests = [];
$knownNames = [];
$knownPhones = [];

foreach ($baseRows as $row) {
    $id = (int) ($row['Guest ID'] ?? 0);
    if ($id < 1 || isset($guests[$id])) {
        throw new RuntimeException('Invalid or duplicate base guest ID: '.$id);
    }

    $phone = $phoneNumbers->normalizeOrFail((string) ($row['Phone number'] ?? ''));
    $name = trim((string) ($row['Guest name'] ?? ''));
    $generatedAt = trim((string) ($row['Generated at'] ?? ''));

    $guests[$id] = [
        'id' => $id,
        'name' => $name,
        'phone_number' => $phone,
        'pass_type' => passTypeFromLabel((string) ($row['Pass type'] ?? '')),
        'allowed_entries' => (int) ($row['Allowed entries'] ?? 0),
        'used_entries' => (int) ($row['Used entries'] ?? 0),
        'status' => (string) ($row['Guest status'] ?? Guest::STATUS_ACTIVE),
        'qr_status' => (string) ($row['QR status'] ?? 'active'),
        'generated_at' => $generatedAt,
        'revoked_at' => trim((string) ($row['Revoked at'] ?? '')),
        'download_file_name' => trim((string) ($row['Download file name'] ?? '')),
    ];
    $knownNames[normalizedName($name)] = true;
    $knownPhones[$phone] = true;
}

$nextId = max(array_keys($guests)) + 1;
$duplicatesSkipped = 0;

foreach ($supplementalRows as $row) {
    $name = trim((string) ($row['name'] ?? ''));
    $phone = $phoneNumbers->normalizeOrFail((string) ($row['phone_number'] ?? ''));

    if (isset($knownNames[normalizedName($name)]) || isset($knownPhones[$phone])) {
        $duplicatesSkipped++;
        continue;
    }

    $guests[$nextId] = [
        'id' => $nextId,
        'name' => $name,
        'phone_number' => $phone,
        'pass_type' => passTypeFromLabel((string) ($row['pass_type'] ?? '')),
        'allowed_entries' => (int) ($row['allowed_entries'] ?? 0),
        'used_entries' => 0,
        'status' => Guest::STATUS_ACTIVE,
        'qr_status' => 'active',
        'generated_at' => '',
        'revoked_at' => '',
        'download_file_name' => '',
    ];
    $knownNames[normalizedName($name)] = true;
    $knownPhones[$phone] = true;
    $nextId++;
}

$assets = [];
foreach (glob($qrDirectory.DIRECTORY_SEPARATOR.'guest-*.png') ?: [] as $path) {
    if (! preg_match('/^guest-(\d+)-/', basename($path), $matches)) {
        continue;
    }

    $id = (int) $matches[1];
    if (isset($assets[$id])) {
        throw new RuntimeException('Multiple QR assets found for guest '.$id);
    }

    $assets[$id] = qrAsset($path, $qrDirectory);
}

ksort($guests);
ksort($assets);

if (array_keys($guests) !== array_keys($assets)) {
    throw new RuntimeException(
        'Guest and QR asset IDs differ. Guests without assets: '.implode(',', array_diff(array_keys($guests), array_keys($assets)))
        .'; assets without guests: '.implode(',', array_diff(array_keys($assets), array_keys($guests)))
    );
}

foreach ($guests as $id => &$guest) {
    $guest['qr'] = $assets[$id];
    $guest['generated_at'] = $guest['generated_at'] ?: $assets[$id]['modified_at'];

    if ($guest['download_file_name'] !== ''
        && preg_match('/_qr_(\d{4})\.png$/', $guest['download_file_name'], $matches)
        && $matches[1] !== $assets[$id]['manual_code']) {
        throw new RuntimeException('Manual code mismatch for guest '.$id);
    }
}
unset($guest);

$manualCodes = array_column(array_column($guests, 'qr'), 'manual_code');
$tokens = array_column(array_column($guests, 'qr'), 'token');

if (count($manualCodes) !== count(array_unique($manualCodes)) || count($tokens) !== count(array_unique($tokens))) {
    throw new RuntimeException('Recovered manual codes or QR tokens are not unique.');
}

printf(
    "Recovery validation passed: %d guests (%d base + %d supplemental, %d duplicates skipped), %d QR assets, %d used entries.\n",
    count($guests),
    count($baseRows),
    count($guests) - count($baseRows),
    $duplicatesSkipped,
    count($assets),
    array_sum(array_column($guests, 'used_entries')),
);
printf("Guest ID range: %d-%d. First: %s. Last: %s.\n", array_key_first($guests), array_key_last($guests), reset($guests)['name'], end($guests)['name']);

if (! $apply) {
    echo "Dry run only. Re-run with --apply after backing up the current database.\n";
    exit(0);
}

if (Guest::query()->exists() || QrCode::query()->exists() || User::query()->exists()) {
    throw new RuntimeException('Recovery refused because users, guests, or QR codes already exist.');
}

DB::transaction(function () use ($guests): void {
    $event = Event::query()->find(1) ?? new Event();
    $event->id = 1;
    $event->forceFill([
        'event_name' => 'ON Wedding',
        'status' => Event::STATUS_ACTIVE,
    ]);
    $event->save();

    User::query()->create([
        'name' => 'Wedding Admin',
        'email' => 'admin@example.com',
        'password' => Hash::make('password'),
        'role' => User::ROLE_ADMIN,
        'gate_name' => null,
    ]);
    User::query()->create([
        'name' => 'Main Gate Scanner',
        'email' => 'scanner@example.com',
        'password' => Hash::make('password'),
        'role' => User::ROLE_SCANNER,
        'event_id' => $event->id,
        'gate_name' => 'Main Gate',
    ]);

    foreach ($guests as $data) {
        $guest = new Guest();
        $guest->timestamps = false;
        $guest->id = $data['id'];
        $guest->forceFill([
            'event_id' => $event->id,
            'manual_code' => $data['qr']['manual_code'],
            'name' => $data['name'],
            'phone_number' => $data['phone_number'],
            'pass_type' => $data['pass_type'],
            'allowed_entries' => $data['allowed_entries'],
            'used_entries' => $data['used_entries'],
            'status' => $data['status'],
            'created_at' => $data['generated_at'],
            'updated_at' => $data['generated_at'],
        ]);
        $guest->save();

        QrCode::query()->create([
            'guest_id' => $guest->id,
            'qr_token' => $data['qr']['token'],
            'qr_image_path' => $data['qr']['path'],
            'is_active' => $data['qr_status'] === 'active',
            'generated_at' => $data['generated_at'],
            'revoked_at' => $data['revoked_at'] !== '' ? $data['revoked_at'] : null,
        ]);
    }
}, 3);

printf(
    "Recovery applied: %d users, %d event, %d guests, %d QR codes.\n",
    User::query()->count(),
    Event::query()->count(),
    Guest::query()->count(),
    QrCode::query()->count(),
);
